<?php

namespace App\Tasks\Maintenance\Checks;

use App\Actions\Media\DeleteCollection;
use App\Actions\Media\RemoveOrphanTitles;
use App\Catalog\Creators\CreatorKind;
use App\Library\LibraryHolders;
use App\Models\Company;
use App\Models\MediaItem;
use App\Models\MediaList;
use App\Models\Person;
use App\Support\Numbers;
use App\Tasks\Data\TaskLine;
use App\Tasks\Maintenance\CheckLevel;
use App\Tasks\Maintenance\CheckReport;
use App\Tasks\Maintenance\Contracts\MaintenanceCheck;
use App\Tasks\Maintenance\Data\CheckLine;
use App\Tasks\Maintenance\MaintenanceWindow;
use App\Tasks\Maintenance\OrphanKind;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use LogicException;

/**
 * Removes what the catalog keeps and nobody holds: titles opened once,
 * people and companies nothing credits or follows any more, collections left
 * empty. In short chunks within the night's time, and only after a grace: a
 * title saved or opened lately may be taken up yet. Nothing anybody holds is
 * ever touched; another user's history keeps a title for everyone. A title
 * removed and opened again is fetched anew.
 */
final readonly class OrphanSweep implements MaintenanceCheck
{
    public const CODE = 'orphan-sweep';

    public function __construct(
        private LibraryHolders $holders,
        private RemoveOrphanTitles $removeTitles,
        private DeleteCollection $deleteCollection,
    ) {}

    public function label(): string
    {
        return __('tasks.orphan_sweep');
    }

    public function window(): MaintenanceWindow
    {
        return MaintenanceWindow::Nightly;
    }

    public function order(): int
    {
        return 10;
    }

    public function applies(): bool
    {
        return true;
    }

    public function run(CheckReport $report): void
    {
        $grace = now()->subDays(config()->integer('tracker.retention.orphan_grace_days'))->toImmutable();
        $chunk = config()->integer('tracker.retention.orphan_chunk');

        if ($chunk < 1) {
            throw new LogicException('tracker.retention.orphan_chunk must be at least 1.');
        }
        $deadline = now()->addSeconds(config()->integer('tracker.retention.orphan_seconds_per_night'))->toImmutable();
        $removed = [];

        // Kind by kind, each counted after the one before went: the credits of
        // a removed title go with it, and leave its people to the next kind.
        $titles = $this->orphanTitles($grace);
        $removed[OrphanKind::Titles->value] = 0;

        // The titles are counted once and taken chunk by chunk off that count.
        foreach (array_chunk($titles, $chunk) as $ids) {
            if (now()->gte($deadline)) {
                break;
            }

            $removed[OrphanKind::Titles->value] += $this->removeTitles->execute($ids);
        }

        $report->add($this->line(OrphanKind::Titles, count($titles), $removed[OrphanKind::Titles->value]));

        $people = $this->holders->unheldCreators(Person::query(), CreatorKind::Person)
            ->where('created_at', '<', $grace)
            ->whereNotExists(fn (QueryBuilder $credits) => $credits->selectRaw('1')->from('credits')->whereColumn('credits.person_id', 'people.id'));
        $count = (clone $people)->count();
        $removed[OrphanKind::People->value] = $this->inChunks(
            fn (): array => Numbers::ints((clone $people)->orderBy('id')->limit($chunk)->pluck('id')),
            fn (array $ids): int => Numbers::int(Person::query()->whereIn('id', $ids)->delete()) ?? 0,
            $deadline,
        );
        $report->add($this->line(OrphanKind::People, $count, $removed[OrphanKind::People->value]));

        $companies = $this->holders->unheldCreators(Company::query(), CreatorKind::Company)->where('created_at', '<', $grace);
        $count = (clone $companies)->count();
        $removed[OrphanKind::Companies->value] = $this->inChunks(
            fn (): array => Numbers::ints((clone $companies)->orderBy('id')->limit($chunk)->pluck('id')),
            fn (array $ids): int => Numbers::int(Company::query()->whereIn('id', $ids)->delete()) ?? 0,
            $deadline,
        );
        $report->add($this->line(OrphanKind::Companies, $count, $removed[OrphanKind::Companies->value]));

        $collections = $this->emptyCollections();
        $count = (clone $collections)->count();
        $removed[OrphanKind::Collections->value] = $this->inChunks(
            fn (): array => Numbers::ints((clone $collections)->orderBy('id')->limit($chunk)->pluck('id')),
            function (array $ids): int {
                foreach (MediaList::query()->whereIn('id', $ids)->get() as $collection) {
                    $this->deleteCollection->execute($collection);
                }

                return count($ids);
            },
            $deadline,
        );
        $report->add($this->line(OrphanKind::Collections, $count, $removed[OrphanKind::Collections->value]));

        if (array_sum($removed) > 0) {
            $report->note('tasks.removed_orphans', $removed);
        }
    }

    /**
     * Removes chunk after chunk, each asked for anew and its own short write,
     * until none is left or the night's time is up: a long backlog goes in one
     * night without holding the database's single writer for long, and a huge
     * one the nights after.
     *
     * @param  Closure(): list<int>  $next  the next chunk's ids, empty when none is left
     * @param  Closure(list<int>): int  $remove  how many of them went
     */
    private function inChunks(Closure $next, Closure $remove, CarbonImmutable $deadline): int
    {
        $removed = 0;

        while (now()->lt($deadline) && ($ids = $next()) !== []) {
            $went = $remove($ids);
            $removed += $went;

            // A chunk nothing went from would only come back the same.
            if ($went === 0) {
                break;
            }
        }

        return $removed;
    }

    /**
     * The roots saved before the grace and opened by nobody since it began
     * that nothing points at, asked as the removal asks it.
     *
     * @return list<int>
     */
    private function orphanTitles(CarbonImmutable $grace): array
    {
        $opened = now()->subDays(config()->integer('tracker.retention.orphan_recent_view_days'));

        return $this->holders->unreferencedOf(Numbers::ints(MediaItem::query()
            ->whereNull('parent_id')
            ->where('created_at', '<', $grace)
            ->where(fn (Builder $views) => $views->whereNull('viewed_at')->orWhere('viewed_at', '<', $opened))
            ->orderBy('id')
            ->pluck('id')));
    }

    /**
     * A collection's own list with no part left on it and nothing in a
     * library pointing at it: the next visit to the collection makes it again.
     *
     * @return Builder<MediaList>
     */
    private function emptyCollections(): Builder
    {
        return $this->holders->unheldCreators(MediaList::query()->collections(), CreatorKind::Collection)
            ->whereNotExists(fn (QueryBuilder $parts) => $parts->selectRaw('1')->from('list_items')->whereColumn('list_items.list_id', 'lists.id'));
    }

    private function line(OrphanKind $kind, int $count, int $removed): CheckLine
    {
        $state = match (true) {
            $count === 0 => new TaskLine('tasks.orphan_state_none'),
            $removed < $count => new TaskLine('tasks.orphan_state_removed_of', ['count' => $removed, 'total' => $count]),
            default => new TaskLine('tasks.orphan_state_removed', ['count' => $removed]),
        };

        // Nothing here reads away from the row, so the state stands for the line too.
        return new CheckLine(CheckLevel::Ok, $state, subject: $kind->value, label: new TaskLine($kind->label()), state: $state);
    }
}
