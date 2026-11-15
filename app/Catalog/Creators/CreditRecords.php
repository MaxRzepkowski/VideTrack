<?php

namespace App\Catalog\Creators;

use App\Catalog\Data\CreditData;
use App\Catalog\Department;
use App\Catalog\TitleStore;
use App\Models\Credit;
use App\Models\MediaItem;
use App\Models\Person;

/**
 * Stores a title's cast and crew, so a detail page has something to show when
 * the provider is unreachable ({@see DetailsFromLibrary}).
 *
 * Runs inside the write transaction of {@see TitleStore} on data
 * already fetched — never issues a request of its own, because SQLite has a
 * single writer and an HTTP call inside the transaction locks the whole
 * database for its duration.
 */
final readonly class CreditRecords
{
    /**
     * A blockbuster credits hundreds of people and a show's aggregate credits
     * more still. The page shows the top of the billing anyway, and this is a
     * SQLite file on someone's home server — the cap is the only brake there
     * is.
     */
    public const MAX_CAST = 20;

    /**
     * The same brake for the crew: a long show's aggregate credits list its
     * whole writers' room, a hundred names and more.
     */
    public const MAX_CREW = 40;

    /** Crew worth a row: who made it, not who catered it. */
    public const CREW_DEPARTMENTS = [Department::Directing, Department::Writing];

    public function __construct(private PersonRecords $people) {}

    /**
     * @param  list<CreditData>  $cast
     * @param  list<CreditData>  $crew
     */
    public function store(MediaItem $item, array $cast, array $crew): void
    {
        $wanted = [...$this->topCast($cast), ...$this->coreCrew($crew)];

        // An empty payload is far more often a provider that serves no credits
        // for this type (books, comics) or a thinned-out response than a title
        // that genuinely lost its whole cast — so it leaves the stored rows
        // alone instead of emptying the fallback the moment it is needed.
        if ($wanted === []) {
            return;
        }

        $keep = [];

        foreach ($wanted as $credit) {
            $person = $this->people->fromCredit($credit);

            $keep[] = $this->upsert($item, $person, $credit)->id;
        }

        // Synchronised, not appended: without this every metadata refresh
        // would add a second copy of every credit.
        Credit::query()
            ->where('media_item_id', $item->id)
            ->whereNotIn('id', $keep)
            ->delete();
    }

    private function upsert(MediaItem $item, Person $person, CreditData $credit): Credit
    {
        $row = Credit::query()->firstOrNew([
            'media_item_id' => $item->id,
            'person_id' => $person->id,
            'department' => $credit->department->value,
            'role' => $credit->role,
        ]);

        $row->fill([
            'order' => $credit->order,
            'episode_count' => $credit->episodeCount,
        ]);

        if (! $row->exists || $row->isDirty()) {
            $row->save();
        }

        return $row;
    }

    /**
     * @param  list<CreditData>  $cast
     * @return list<CreditData>
     */
    private function topCast(array $cast): array
    {
        usort($cast, fn (CreditData $a, CreditData $b): int => $a->order <=> $b->order);

        return array_slice($cast, 0, self::MAX_CAST);
    }

    /**
     * @param  list<CreditData>  $crew
     * @return list<CreditData>
     */
    private function coreCrew(array $crew): array
    {
        return array_slice(array_values(array_filter(
            $crew,
            fn (CreditData $credit): bool => in_array($credit->department, self::CREW_DEPARTMENTS, true),
        )), 0, self::MAX_CREW);
    }
}
