<?php

namespace App\Catalog\Relations;

use App\Catalog\CardSnapshot;
use App\Catalog\Data\MediaSummary;
use App\Catalog\ItemMetadata;
use App\Catalog\MediaItemSummaries;
use App\Models\FranchiseMember;
use App\Models\MediaItem;
use App\Models\RelatedTitle;
use App\Support\QueryRow;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\DB;

/**
 * The one reader and writer of the ties between titles and of when each
 * title was last asked about. A tie is a fact about the catalogue, the same
 * for every user.
 */
final readonly class RelationStore
{
    public function __construct(private TieRules $rules) {}

    /** Days an answer stands: what a title is tied to changes rarely. */
    public const FRESH_DAYS = 90;

    /** Rows read or written in one statement. */
    private const CHUNK = 500;

    /**
     * Stored roots an outside source can be asked about (they carry its id)
     * that were never asked, or long enough ago; the never asked first.
     *
     * @return Collection<int, MediaItem>
     */
    public function due(CarbonInterface $now, int $limit): Collection
    {
        return $this->dueTitles($now)
            ->orderByRaw('title_relation_checks.checked_at is not null')
            ->orderBy('title_relation_checks.checked_at')
            ->orderBy('media_items.id')
            ->limit($limit)
            ->get('media_items.*');
    }

    public function countDue(CarbonInterface $now): int
    {
        return $this->dueTitles($now)->count();
    }

    /**
     * @return Builder<MediaItem>
     */
    private function dueTitles(CarbonInterface $now): Builder
    {
        return ItemMetadata::having(MediaItem::query(), 'wikidataId')
            ->whereNull('media_items.parent_id')
            ->whereNull('media_items.gone_at')
            ->leftJoin('title_relation_checks', 'title_relation_checks.media_item_id', '=', 'media_items.id')
            ->where(fn ($aged) => $aged
                ->whereNull('title_relation_checks.checked_at')
                ->orWhere('title_relation_checks.checked_at', '<', $now->copy()->subDays(self::FRESH_DAYS)));
    }

    /** The id the outside source knows the title by. */
    public function sourceIdOf(MediaItem $item): ?string
    {
        return ItemMetadata::fromStored($item->metadata)->wikidataId;
    }

    /**
     * What the title is tied to as of now, replacing what was stored, and
     * the note that it was asked about (also when the answer is nothing).
     *
     * @param  list<array{TitleRelation, MediaSummary}>  $ties
     */
    public function replace(MediaItem $item, array $ties): void
    {
        DB::transaction(function () use ($item, $ties): void {
            RelatedTitle::query()->where('media_item_id', $item->id)->delete();

            $rows = [];

            foreach ($ties as [$relation, $summary]) {
                $columns = CardSnapshot::columns($summary);
                $rows["{$relation->value}:{$summary->key()}"] = [...$columns, 'card' => json_encode($columns['card'], JSON_THROW_ON_ERROR), 'media_item_id' => $item->id, 'relation' => $relation->value];
            }

            if ($rows !== []) {
                RelatedTitle::query()->insert(array_values($rows));
            }

            DB::table('title_relation_checks')->upsert(
                [['media_item_id' => $item->id, 'checked_at' => now()]],
                ['media_item_id'],
                ['checked_at'],
            );
        });
    }

    /**
     * The franchises a title belongs to as of now, replacing what was stored.
     *
     * @param  list<string>  $franchises  the source's ids
     */
    public function linkFranchises(MediaItem $item, array $franchises): void
    {
        DB::transaction(function () use ($item, $franchises): void {
            DB::table('title_franchises')->where('media_item_id', $item->id)->delete();
            DB::table('title_franchises')->insert(array_map(fn (string $franchise): array => ['media_item_id' => $item->id, 'franchise' => $franchise], array_values(array_unique($franchises))));
            DB::table('franchises')->insertOrIgnore(array_map(fn (string $franchise): array => ['source_id' => $franchise], array_values(array_unique($franchises))));
        });
    }

    /**
     * The franchises a stored title belongs to, by the source's ids.
     *
     * @return list<string>
     */
    public function franchisesOf(MediaItem $item): array
    {
        $franchises = [];

        foreach (DB::table('title_franchises')->where('media_item_id', $item->id)->orderBy('franchise')->get(['franchise']) as $link) {
            $franchises[] = QueryRow::text($link, 'franchise');
        }

        return $franchises;
    }

    /**
     * Franchises some stored title belongs to whose titles were never
     * listed, or long enough ago.
     *
     * @return list<string>
     */
    public function franchisesDue(CarbonInterface $now, int $limit): array
    {
        $rows = DB::table('franchises')
            ->whereIn('source_id', DB::table('title_franchises')->select('franchise'))
            ->where(fn (QueryBuilder $aged) => $aged->whereNull('checked_at')->orWhere('checked_at', '<', $now->copy()->subDays(self::FRESH_DAYS)))
            ->orderBy('source_id')
            ->limit($limit)
            ->get(['source_id']);

        $due = [];

        foreach ($rows as $row) {
            $due[] = QueryRow::text($row, 'source_id');
        }

        return $due;
    }

    /**
     * Every film and show of a franchise as of now, replacing what was
     * stored, and the note that it was listed.
     *
     * @param  list<array{MediaSummary, string|null, int|null}>  $members  each title's card, the provider collection it is a part of and how many rated it (null where it is stored here)
     */
    public function fillFranchise(string $franchise, array $members): void
    {
        DB::transaction(function () use ($franchise, $members): void {
            FranchiseMember::query()->where('franchise', $franchise)->delete();

            $rows = [];

            foreach ($members as [$summary, $collectionRef, $votes]) {
                $columns = CardSnapshot::columns($summary);
                $rows[$summary->key()] = [...$columns, 'card' => json_encode($columns['card'], JSON_THROW_ON_ERROR), 'franchise' => $franchise, 'votes' => $votes, 'collection_ref' => $collectionRef];
            }

            foreach (array_chunk(array_values($rows), self::CHUNK) as $chunk) {
                FranchiseMember::query()->insert($chunk);
            }

            DB::table('franchises')->where('source_id', $franchise)->update(['checked_at' => now()]);
        });
    }

    /**
     * The titles tied to this one, as its page shows them.
     *
     * @return list<TitleTie>
     */
    public function of(MediaItem $item): array
    {
        return $this->ofMany([$item])[$item->id] ?? [];
    }

    /**
     * The ties of each title, read from both ends: what was found asking
     * about the title, and what was found asking about another stored title
     * that names this one. The source files a tie on one item or the other,
     * and half the titles it names are not stored here to be asked about, so
     * only both ends together make "A is B's spin-off" also say "B is A's
     * original".
     *
     * @param  iterable<MediaItem>  $items  roots
     * @return array<int, list<TitleTie>> by media item id; a title with none has no entry
     */
    public function ofMany(iterable $items): array
    {
        $byId = [];
        $idsByRef = [];

        foreach ($items as $item) {
            $byId[$item->id] = $item;
            $idsByRef[$item->type][$item->provider_ref] = $item->id;
        }

        $ties = [];

        foreach (array_chunk(array_keys($byId), self::CHUNK) as $chunk) {
            foreach (RelatedTitle::query()->whereIn('media_item_id', $chunk)->orderBy('id')->get() as $stored) {
                $ties[$stored->media_item_id][] = new TitleTie($stored->relation, CardSnapshot::of($stored));
            }
        }

        foreach ($idsByRef as $type => $ids) {
            foreach (array_chunk(array_map(strval(...), array_keys($ids)), self::CHUNK) as $chunk) {
                $naming = RelatedTitle::query()
                    ->where('type', $type)
                    ->whereIn('ref', $chunk)
                    ->whereHas('mediaItem', fn ($source) => $source->whereNull('gone_at'))
                    ->with('mediaItem')
                    ->orderBy('id')
                    ->get();

                foreach ($naming as $stored) {
                    $ties[$ids[$stored->ref]][] = new TitleTie($stored->relation->inverse(), MediaItemSummaries::summary($stored->mediaItem));
                }
            }
        }

        foreach ($this->universes($byId) as $id => $members) {
            $ties[$id] = [...($ties[$id] ?? []), ...$members];
        }

        $settled = [];

        foreach ($ties as $id => $ofTitle) {
            $shown = $this->rules->settle($byId[$id], $ofTitle);

            if ($shown !== []) {
                $settled[$id] = $shown;
            }
        }

        return $settled;
    }

    /**
     * The other films and shows of each title's franchises, each with the
     * provider collection it is a part of.
     *
     * @param  array<int, MediaItem>  $byId
     * @return array<int, list<TitleTie>>
     */
    private function universes(array $byId): array
    {
        $franchisesOf = [];

        foreach (array_chunk(array_keys($byId), self::CHUNK) as $chunk) {
            foreach (DB::table('title_franchises')->whereIn('media_item_id', $chunk)->get(['media_item_id', 'franchise']) as $link) {
                $franchisesOf[QueryRow::int($link, 'media_item_id')][] = QueryRow::text($link, 'franchise');
            }
        }

        if ($franchisesOf === []) {
            return [];
        }

        $membersOf = [];

        foreach (array_chunk(array_values(array_unique(array_merge([], ...array_values($franchisesOf)))), self::CHUNK) as $chunk) {
            foreach (FranchiseMember::query()->whereIn('franchise', $chunk)->orderBy('release_date')->orderBy('id')->get() as $member) {
                $membersOf[$member->franchise][] = $member;
            }
        }

        $ties = [];

        foreach ($franchisesOf as $id => $franchises) {
            $title = $byId[$id];

            foreach ($franchises as $franchise) {
                foreach ($membersOf[$franchise] ?? [] as $member) {
                    if (! ($member->type === $title->type && $member->ref === $title->provider_ref)) {
                        $ties[$id][] = new TitleTie(TitleRelation::SameUniverse, CardSnapshot::of($member), $member->collection_ref);
                    }
                }
            }
        }

        return $ties;
    }
}
