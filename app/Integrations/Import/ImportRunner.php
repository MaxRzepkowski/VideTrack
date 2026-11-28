<?php

namespace App\Integrations\Import;

use App\Actions\Library\AddToList;
use App\Actions\Library\BackfillCreatorFollow;
use App\Actions\Library\BackfillCycle;
use App\Actions\Library\BackfillDrop;
use App\Actions\Library\BackfillFollow;
use App\Actions\Library\BackfillPlay;
use App\Actions\Library\BackfillRating;
use App\Actions\Library\BackfillStatus;
use App\Actions\Library\CreateList;
use App\Actions\Library\SetFavorite;
use App\Catalog\Creators\CreatorKind;
use App\Catalog\Creators\CreatorPages;
use App\Catalog\Creators\CreatorRef;
use App\Catalog\Creators\Creators;
use App\Catalog\Creators\UnknownCreator;
use App\Catalog\ExternalIdResolver;
use App\Catalog\HierarchyPath;
use App\Catalog\MediaKind;
use App\Catalog\MediaTypeRegistry;
use App\Catalog\TitleStore;
use App\Integrations\Import\Contracts\ImportSource;
use App\Integrations\Import\Data\ImportInput;
use App\Integrations\Import\Data\ImportListRef;
use App\Integrations\Import\Data\ImportOutcome;
use App\Integrations\Import\Data\ImportSelection;
use App\Integrations\Import\Data\MediaSubject;
use App\Integrations\Import\Rows\CompanyListRow;
use App\Integrations\Import\Rows\CreatorFollowRow;
use App\Integrations\Import\Rows\EpisodeRunRow;
use App\Integrations\Import\Rows\ImportRow;
use App\Integrations\Import\Rows\ListRow;
use App\Integrations\Import\Rows\MediaRow;
use App\Integrations\Import\Rows\PersonListRow;
use App\Integrations\Import\Rows\SkipRow;
use App\Library\BackfillBatch;
use App\Library\Data\ListTarget;
use App\Library\LibraryProjector;
use App\Library\RatingScale;
use App\Library\ScrobbleDedup;
use App\Library\WatchedAt;
use App\Models\MediaItem;
use App\Models\MediaList;
use App\Models\User;
use App\Support\ExternalServiceException;
use App\Tasks\ProgressReporter;
use Carbon\CarbonImmutable;
use Illuminate\Support\Traits\Localizable;
use LogicException;

/**
 * One import, whatever the source: the source turns its input into rows,
 * the runner places each row and writes it through the library's actions
 * in their backfill flavour — additive, idempotent, dated at the source.
 * The job around it only deals with the queue and the task run.
 */
final readonly class ImportRunner
{
    use Localizable;

    public function __construct(
        private MediaTypeRegistry $types,
        private TitleStore $persist,
        private ExternalIdResolver $resolver,
        private TitleMatcher $titles,
        private PlaybackLength $length,
        private HierarchyPath $hierarchy,
        private Creators $creators,
        private CreatorPages $pages,
        private ScrobbleDedup $dedup,
        private BackfillCycle $cycle,
        private BackfillPlay $play,
        private BackfillDrop $drop,
        private BackfillRating $rate,
        private BackfillStatus $status,
        private SetFavorite $favorite,
        private AddToList $addToList,
        private CreateList $createList,
        private BackfillFollow $follow,
        private BackfillCreatorFollow $creatorFollow,
        private LibraryProjector $projector,
    ) {}

    /**
     * The source reads in the user's language, so what it refuses a file or
     * an account with reads as the rest of the report does.
     */
    public function run(User $user, ImportSource $source, ImportInput $input, ImportSelection $selection, ProgressReporter $progress): ImportOutcome
    {
        // A file can bring thousands of plays on a few hundred titles: each
        // title is projected once, when the run ends, not once per play.
        return $this->projector->deferring($user, function () use ($user, $source, $input, $selection, $progress): ImportOutcome {
            return $this->withLocale($user->preferredLocale(), fn (): ImportOutcome => $this->runIn($user, $source, $input, $selection, $progress));
        });
    }

    private function runIn(User $user, ImportSource $source, ImportInput $input, ImportSelection $selection, ProgressReporter $progress): ImportOutcome
    {
        $manifest = $source->analyze($input);

        if (! $manifest->valid) {
            return ImportOutcome::refused((string) $manifest->error);
        }

        $run = new ImportRun($user, $user->preferredLocale(), BackfillBatch::begin($user), $this->types, $this->persist);
        $total = $manifest->total($selection);
        $processed = 0;
        $rows = $source->rows($input, $selection);

        try {
            foreach ($rows as $row) {
                $this->apply($run, $row);
                $processed++;
                $progress->report(fn (): string => $run->progress($processed, $total));
            }

            foreach ($run->takeStatuses() as $write) {
                $write();
            }
        } finally {
            // A retry finds written what the attempt before it wrote, so its
            // writes announce nothing; that attempt may have been killed
            // before projecting them. Whatever the run placed is projected.
            $this->projector->projectItems($user, $run->placedIds());
        }

        $position = $rows->getReturn();

        return ImportOutcome::finished($run->report($total, $processed), $run->errors(), is_array($position) ? $position : null);
    }

    private function apply(ImportRun $run, ImportRow $row): void
    {
        match (true) {
            $row instanceof SkipRow => $run->skip($row->key, $row->replace),
            $row instanceof ListRow => $this->list($run, $row->list),
            $row instanceof PersonListRow => $this->creatorOnList($run, $row->list, new CreatorRef(CreatorKind::Person, $row->provider, $row->ref), $row->name, $row->addedAt),
            $row instanceof CompanyListRow => $this->creatorOnList($run, $row->list, new CreatorRef($row->kind, $row->provider, $row->ref), $row->name, $row->addedAt),
            $row instanceof MediaRow => $this->media($run, $row),
            $row instanceof EpisodeRunRow => $this->episodes($run, $row),
            $row instanceof CreatorFollowRow => $this->creatorFollow($run, $row),
            default => throw new LogicException('An import row of a kind the runner does not know: '.$row::class),
        };
    }

    private function media(ImportRun $run, MediaRow $row): void
    {
        $item = $this->resolve($run, $row->subject);

        if ($item === null) {
            return;
        }

        $user = $run->user;

        foreach ($row->cycles as $cycle) {
            // Counted only when the run is new: one the library held already stands.
            $run->wrote('cycles', $this->cycle->execute($user, $item, $cycle->number, $cycle->startedAt)?->wasRecentlyCreated === true);
        }

        foreach ($row->plays as $play) {
            if ($this->dedup->heardBefore($user, $item, $play->at, $play->dating, $play->outcome, $play->record)) {
                continue;
            }

            if ($play->playedSeconds !== null && ($short = $this->length->shortfall($row->subject->label, $item->runtime, $play->playedSeconds)) !== null) {
                $run->skip($short->key, $short->replace);

                continue;
            }

            $run->wrote('plays', $this->play->execute($user, $item, $play->at, $play->outcome, $play->amount, $play->cycle, $play->dating, $play->source));
        }

        if ($row->rating !== null) {
            if (RatingScale::holds($row->rating->value)) {
                $run->wrote('ratings', $this->rate->execute($user, $item, $row->rating->value, $row->rating->at));
            } else {
                $run->skip('import.skip_rating_off_scale', ['label' => $row->subject->label, 'rating' => $row->rating->value, 'min' => RatingScale::MIN, 'max' => RatingScale::MAX]);
            }
        }

        if ($row->status !== null) {
            $status = $row->status;
            $run->afterHistory(fn () => $run->wrote('statuses', $this->status->execute($user, $item, $status->status, $run->batch, $status->at, $status->progress)));
        }

        if ($row->drop !== null) {
            $drop = $row->drop;
            $droppedAt = $drop->at === null ? WatchedAt::releaseDateOrNow() : WatchedAt::at($drop->at);
            $run->afterHistory(fn () => $run->wrote('drops', $this->drop->execute($user, $item, $droppedAt, $run->batch, $drop->amount)));
        }

        if ($row->watchlist !== null) {
            $run->wrote('list_items', $this->addToList->execute($user, $user->watchlist(), ListTarget::item($item->id), $row->watchlist->at));
        }

        if ($row->favorite !== null) {
            $run->wrote('favorites', $this->favorite->execute($user, $item, true, $row->favorite->at));
        }

        foreach ($row->lists as $entry) {
            $run->wrote('list_items', $this->addToList->execute($user, $this->list($run, $entry->list), ListTarget::item($item->id), $entry->addedAt));
        }

        if ($row->follow !== null) {
            $item->parent_id === null
                ? $run->wrote('follows', $this->follow->execute($user, $item, $row->follow))
                : $run->skip('import.skip_follow_of_a_part', ['label' => $row->subject->label]);
        }
    }

    /**
     * The bell on a creator. A collection the provider no longer serves, or
     * one this instance has no provider for, is reported like a title.
     */
    private function creatorFollow(ImportRun $run, CreatorFollowRow $row): void
    {
        try {
            $run->wrote('follows', $this->creatorFollow->execute($run->user, $row->creator, $row->name, $row->mode, $run->locale));
        } catch (UnknownCreator) {
            $run->skip('import.skip_no_provider', ['label' => $row->name, 'provider' => $row->creator->provider, 'type' => $row->creator->kind->value]);
        } catch (ExternalServiceException $e) {
            $run->unavailable($row->name, $e);
        }
    }

    /**
     * One play per episode of the run, all on the row's date. The first
     * episode the provider cannot place ends the run — the season is shorter
     * there, or numbered differently — and is reported once. A show the
     * provider does not know at all is reported by its ids.
     */
    private function episodes(ImportRun $run, EpisodeRunRow $row): void
    {
        for ($number = $row->first; $number <= $row->last; $number++) {
            try {
                $resolved = $this->resolver->resolve([], MediaKind::Episode, $run->locale, $row->season, $number, $row->seriesExternalIds);
            } catch (ExternalServiceException $e) {
                $run->unavailable($row->label, $e);

                return;
            }
            $item = $resolved === null
                ? null
                : $run->resolve($resolved->type, $this->types->get($resolved->type)->provider, $resolved->ref, $row->label);

            if ($item === null) {
                if ($resolved === null) {
                    $run->skip('import.skip_no_match', ['label' => $row->label, 'ids' => $this->idList([$row->seriesExternalIds], $run)]);
                }

                return;
            }

            if (! $this->dedup->reportedElsewhere($run->user, $item, $row->at, $row->dating)) {
                $run->wrote('plays', $this->play->execute($run->user, $item, $row->at, dating: $row->dating));
            }
        }
    }

    /**
     * Direct coordinates go straight to the catalog; foreign ids are
     * translated first, then — when the source allows it — the title is
     * searched by name. A title nobody can place is skipped with what was
     * tried, so the report reads "missing" and "unknown" apart.
     */
    private function resolve(ImportRun $run, MediaSubject $subject): ?MediaItem
    {
        if ($subject->isDirect() && $subject->path !== []) {
            $below = $this->hierarchy->descend((string) $subject->type, (string) $subject->ref, $subject->path);

            if ($below === null) {
                $run->skip('import.skip_no_such_place', ['label' => $subject->label, 'provider' => (string) $subject->provider, 'type' => (string) $subject->type, 'ref' => (string) $subject->ref]);

                return null;
            }

            return $run->resolve($below->type, (string) $subject->provider, $below->ref, $subject->label);
        }

        if ($subject->isDirect()) {
            return $run->resolve((string) $subject->type, (string) $subject->provider, (string) $subject->ref, $subject->label);
        }

        // Not direct, so external: a kind always travels with foreign ids.
        $kind = $subject->kind ?? throw new LogicException("{$subject->label}: an external subject without a kind.");

        try {
            $resolved = $this->resolver->resolve(
                $subject->externalIds,
                $kind,
                $run->locale,
                $subject->season,
                $subject->episode,
                $subject->seriesExternalIds,
            );
        } catch (ExternalServiceException $e) {
            // The provider could not be asked: this entry is lost, not the run.
            $run->unavailable($subject->label, $e);

            return null;
        }

        if ($resolved === null && $subject->byTitle !== null) {
            try {
                $resolved = $this->titles->match($kind, $subject->byTitle, $run->locale, $subject->season, $subject->episode, $subject->episodeTitle);
            } catch (ExternalServiceException $e) {
                $run->unavailable($subject->label, $e);

                return null;
            }

            if ($resolved !== null) {
                $run->matchedByTitle("{$resolved->type}:{$resolved->ref}");
            }
        }

        if ($resolved === null) {
            $ids = $this->idList([$subject->externalIds, $subject->seriesExternalIds], $run);

            $subject->byTitle === null
                ? $run->skip('import.skip_no_match', ['label' => $subject->label, 'ids' => $ids])
                : $run->skip('import.skip_no_match_nor_by_name', ['label' => $subject->label, 'ids' => $ids]);

            return null;
        }

        return $run->resolve($resolved->type, $this->types->get($resolved->type)->provider, $resolved->ref, $subject->label);
    }

    /**
     * @param  list<array<string, string>>  $bags
     */
    private function idList(array $bags, ImportRun $run): string
    {
        $ids = [];

        foreach ($bags as $bag) {
            foreach ($bag as $source => $id) {
                if (trim($id) !== '') {
                    $ids[] = "{$source} {$id}";
                }
            }
        }

        return $ids === [] ? __('import.skip_no_ids', [], $run->locale) : implode(', ', $ids);
    }

    /**
     * The target for an imported list: the watchlist for its system key,
     * otherwise the user's own list of the same title — made when missing,
     * left as it is when present. Looked up once per run.
     */
    private function list(ImportRun $run, ImportListRef $ref): MediaList
    {
        return $run->list($ref->key(), function () use ($run, $ref): MediaList {
            $user = $run->user;

            if ($ref->systemKey === MediaList::SYSTEM_WATCHLIST) {
                return $user->watchlist();
            }

            /** @var MediaList|null $existing */
            $existing = $user->lists()->where('title', $ref->title)->whereNull('system_key')->first();

            return $existing ?? $this->createList->execute($user, $ref->title, $ref->description, $ref->isPublic);
        });
    }

    /**
     * A person or a company on a list restores from the row alone (provider,
     * ref, name) — no provider round-trip; the creator's page fills in the
     * rest later. Creators never land on system lists.
     */
    private function creatorOnList(ImportRun $run, ImportListRef $ref, CreatorRef $creator, string $name, ?CarbonImmutable $addedAt): void
    {
        $list = $this->list($run, $ref);

        if ($list->system_key !== null) {
            return;
        }

        // The same door as the bell: a creator no provider here serves has no
        // row to make, whatever the file says.
        if (! $this->pages->serves($creator)) {
            $run->skip('import.skip_no_provider', ['label' => $name, 'provider' => $creator->provider, 'type' => $creator->kind->value]);

            return;
        }

        $row = $this->creators->firstOrCreateNamed($creator, $name);

        $run->wrote('list_items', $this->addToList->execute($run->user, $list, ListTarget::creator($creator->kind, $row->id), $addedAt));
    }
}
