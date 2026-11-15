<?php

namespace App\Catalog\Sets;

use App\Catalog\CardSnapshot;
use App\Models\CatalogSet;
use App\Models\CatalogSetTitle;
use Carbon\CarbonInterface;
use Closure;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;

/**
 * The one reader and writer of the sets copied from providers. A set is
 * wanted for as long as some source keeps asking for it, fetched again once
 * its copy has aged, and forgotten when nobody has asked for a while.
 */
final readonly class SetStore
{
    /** Rows read or written in one statement. */
    private const CHUNK = 200;

    /** Days nobody asked for a set before it is forgotten. */
    public const KEEP_UNWANTED_DAYS = 60;

    /**
     * Note that these sets are needed, in this language, as of now.
     *
     * @param  list<SetKey>  $keys
     */
    public function want(array $keys, string $locale): void
    {
        $now = now();

        foreach (array_chunk($keys, self::CHUNK) as $chunk) {
            CatalogSet::query()->upsert(
                array_map(fn (SetKey $key): array => [
                    'kind' => $key->kind->value,
                    'provider' => $key->provider,
                    'ref' => $key->ref,
                    'locale' => $locale,
                    'wanted_at' => $now,
                ], $chunk),
                ['kind', 'provider', 'ref', 'locale'],
                ['wanted_at'],
            );
        }
    }

    /**
     * Sets still wanted whose copy is missing or has aged, the never fetched first.
     *
     * @return Collection<int, CatalogSet>
     */
    public function due(CarbonInterface $now): Collection
    {
        return CatalogSet::query()
            ->where('wanted_at', '>=', $now->copy()->subDays(self::KEEP_UNWANTED_DAYS))
            ->where(function ($aged) use ($now): void {
                $aged->whereNull('fetched_at');

                foreach (SetKind::cases() as $kind) {
                    $aged->orWhere(fn ($ofKind) => $ofKind
                        ->where('kind', $kind->value)
                        ->where('fetched_at', '<', $now->copy()->subDays($kind->freshDays())));
                }
            })
            ->orderByRaw('fetched_at is not null')
            ->orderBy('fetched_at')
            ->get();
    }

    /** Replace the set's copy with what its provider lists now. */
    public function fill(CatalogSet $set, FetchedSet $fetched): void
    {
        DB::transaction(function () use ($set, $fetched): void {
            $set->titles()->delete();

            $rows = [];

            foreach ($fetched->entries as $position => $entry) {
                $columns = CardSnapshot::columns($entry->media);
                $rows[] = [
                    ...$columns,
                    'card' => json_encode($columns['card'], JSON_THROW_ON_ERROR),
                    'votes' => $entry->media->votes,
                    'department' => $entry->department?->value,
                    'role' => $entry->role,
                    'billing' => $entry->billing,
                    'catalog_set_id' => $set->id,
                    'position' => $position + 1,
                ];
            }

            foreach (array_chunk($rows, self::CHUNK) as $chunk) {
                CatalogSetTitle::query()->insert($chunk);
            }

            $set->update(['name' => $fetched->name, 'poster_path' => $fetched->posterPath, 'fetched_at' => now()]);
        });
    }

    public function forget(CatalogSet $set): void
    {
        $set->delete();
    }

    /** Drop the sets nobody has asked for in a long while; answers how many. */
    public function forgetUnwanted(CarbonInterface $now): int
    {
        $unwanted = CatalogSet::query()->where('wanted_at', '<', $now->copy()->subDays(self::KEEP_UNWANTED_DAYS));
        $count = $unwanted->count();
        $unwanted->delete();

        return $count;
    }

    /**
     * The fetched copies of these sets with their titles, by {@see SetKey::id()}.
     * A reader that needs only some of a set's titles (a person's films as a
     * director, out of everything they are credited on) says which, so the
     * rest is never loaded.
     *
     * @param  list<SetKey>  $keys
     * @param  (Closure(HasMany<CatalogSetTitle, CatalogSet>): mixed)|null  $only
     * @return array<string, CatalogSet>
     */
    public function read(array $keys, string $locale, ?Closure $only = null): array
    {
        $sets = [];

        foreach ($this->byKind($keys) as $kind => $byProvider) {
            foreach ($byProvider as $provider => $refs) {
                foreach (array_chunk($refs, self::CHUNK) as $chunk) {
                    $found = CatalogSet::query()
                        ->where('kind', $kind)
                        ->where('provider', $provider)
                        ->where('locale', $locale)
                        ->whereIn('ref', $chunk)
                        ->whereNotNull('fetched_at')
                        ->with($only === null ? ['titles'] : ['titles' => $only])
                        ->get();

                    foreach ($found as $set) {
                        $sets[$set->key()->id()] = $set;
                    }
                }
            }
        }

        return $sets;
    }

    /**
     * @param  list<SetKey>  $keys
     * @return array<string, array<string, list<string>>> kind => provider => refs
     */
    private function byKind(array $keys): array
    {
        $grouped = [];

        foreach ($keys as $key) {
            $grouped[$key->kind->value][$key->provider][] = $key->ref;
        }

        return $grouped;
    }
}
