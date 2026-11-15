<?php

namespace App\Actions\Media;

use App\Catalog\Creators\CreditRecords;
use App\Catalog\Data\CreatorLink;
use App\Catalog\Data\KeywordData;
use App\Catalog\Department;
use App\Catalog\ItemMetadata;
use App\Http\Data\CollectionCredit;
use App\Http\Data\CollectionOverview;
use App\Models\Credit;
use App\Models\ListItem;
use App\Models\MediaItem;
use App\Models\MediaList;
use App\Models\Person;

/**
 * The saga as a whole, read off the parts the collection list holds: their
 * count, money and time summed, what they share gathered, and the people
 * who came back for more than one. Local data only; nothing is asked of the
 * provider. A list a user typed out has none.
 */
final readonly class BuildCollectionOverview
{
    public function execute(MediaList $list): ?CollectionOverview
    {
        if (! $list->isCollection()) {
            return null;
        }

        $list->loadMissing('items.mediaItem');

        /** @var list<MediaItem> $parts */
        $parts = $list->items->map(fn (ListItem $item): ?MediaItem => $item->mediaItem)->filter()->values()->all();
        $metadata = array_map(fn (MediaItem $part): ItemMetadata => ItemMetadata::fromStored($part->metadata), $parts);

        $released = count(array_filter($parts, fn (MediaItem $part): bool => $part->hasAired(today())));
        $budgets = array_values(array_filter(array_map(fn (ItemMetadata $item): ?int => $item->budget(), $metadata)));
        $revenues = array_values(array_filter(array_map(fn (ItemMetadata $item): ?int => $item->revenue(), $metadata)));
        $runtimes = array_values(array_filter(array_map(fn (MediaItem $part): ?int => $part->runtime, $parts)));

        [$cast, $crew] = $this->credits(array_map(fn (MediaItem $part): int => $part->id, $parts));

        return new CollectionOverview(
            titleCount: count($parts),
            releasedCount: $released,
            upcomingCount: count($parts) - $released,
            budget: $budgets === [] ? null : array_sum($budgets),
            budgetCounted: count($budgets),
            revenue: $revenues === [] ? null : array_sum($revenues),
            revenueCounted: count($revenues),
            totalRuntime: $runtimes === [] ? null : array_sum($runtimes),
            studios: $this->mostShared(array_map(
                fn (ItemMetadata $item): array => array_map(fn (CreatorLink $studio): array => [$studio->name, $studio], $item->studios()),
                $metadata,
            )),
            productionCountries: $this->mostShared(array_map(
                fn (ItemMetadata $item): array => array_map(fn (string $code): array => [$code, $code], $item->productionCountries()),
                $metadata,
            )),
            originalLanguages: $this->mostShared(array_map(
                fn (ItemMetadata $item): array => $item->originalLanguage === null ? [] : [[$item->originalLanguage, $item->originalLanguage]],
                $metadata,
            )),
            certifications: $this->mostShared(array_map(
                fn (ItemMetadata $item): array => $item->certification === null ? [] : [[$item->certification, $item->certification]],
                $metadata,
            )),
            keywords: $this->mostShared(array_map(
                fn (ItemMetadata $item): array => array_map(fn (KeywordData $keyword): array => [$keyword->ref ?? $keyword->name, $keyword], $item->keywords),
                $metadata,
            )),
            cast: $cast,
            crew: $crew,
        );
    }

    /**
     * The values the parts name, each once, the one most parts name first;
     * among equals, the first part's order holds.
     *
     * @template T
     *
     * @param  list<list<array{0: string, 1: T}>>  $perPart  each part's values, keyed by what makes two of them the same
     * @return list<T>
     */
    private function mostShared(array $perPart): array
    {
        $counts = [];
        $values = [];

        foreach ($perPart as $entries) {
            $seenInPart = [];

            foreach ($entries as [$key, $value]) {
                if (isset($seenInPart[$key])) {
                    continue;
                }

                $seenInPart[$key] = true;
                $values[$key] ??= $value;
                $counts[$key] = ($counts[$key] ?? 0) + 1;
            }
        }

        $keys = array_keys($values);
        $order = array_flip($keys);
        usort($keys, fn (string|int $a, string|int $b): int => [$counts[$b], $order[$a]] <=> [$counts[$a], $order[$b]]);

        return array_map(fn (string|int $key) => $values[$key], $keys);
    }

    /**
     * The actors, directors and writers of the parts, a person once per
     * department: whoever is in the most parts first, then by best billing.
     *
     * @param  list<int>  $partIds
     * @return array{0: list<CollectionCredit>, 1: list<CollectionCredit>}
     */
    private function credits(array $partIds): array
    {
        if ($partIds === []) {
            return [[], []];
        }

        // Best billing first, so a person's first credit holds their billing
        // and their characters come in the order they are best known by.
        $credits = Credit::query()
            ->whereIn('media_item_id', $partIds)
            ->whereIn('department', [Department::Acting, ...CreditRecords::CREW_DEPARTMENTS])
            ->with('person')
            ->orderBy('order')
            ->orderBy('id')
            ->get();

        /** @var array<string, array{person: Person, department: Department, billing: int, roles: array<string, true>, parts: array<int, true>}> $people */
        $people = [];

        foreach ($credits as $credit) {
            $person = $credit->person;

            if ($person === null) {
                continue;
            }

            $key = "{$credit->department->value}:{$person->id}";
            $people[$key] ??= ['person' => $person, 'department' => $credit->department, 'billing' => $credit->order, 'roles' => [], 'parts' => []];
            $people[$key]['parts'][$credit->media_item_id] = true;

            if ($credit->role !== null && $credit->role !== '') {
                $people[$key]['roles'][$credit->role] = true;
            }
        }

        uasort($people, fn (array $a, array $b): int => [count($b['parts']), $a['billing'], $a['person']->name] <=> [count($a['parts']), $b['billing'], $b['person']->name]);

        $cast = [];
        $crew = [];

        foreach ($people as $entry) {
            $credit = new CollectionCredit(
                personRef: $entry['person']->provider_ref,
                provider: $entry['person']->provider,
                name: $entry['person']->name,
                profilePath: $entry['person']->profile_path,
                department: $entry['department'],
                roles: array_map(fn (string|int $role): array => ['role' => (string) $role], array_keys($entry['roles'])),
                titleCount: count($entry['parts']),
            );

            if ($entry['department'] === Department::Acting) {
                $cast[] = $credit;
            } else {
                $crew[] = $credit;
            }
        }

        return [$cast, $crew];
    }
}
