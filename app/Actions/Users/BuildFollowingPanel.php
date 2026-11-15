<?php

namespace App\Actions\Users;

use App\Catalog\Creators\CreatorKind;
use App\Catalog\Data\MediaSummary;
use App\Catalog\Data\TypeCount;
use App\Catalog\MediaItemSummaries;
use App\Catalog\MediaTypeRegistry;
use App\Enums\FollowMode;
use App\Models\Follow;
use App\Models\MediaItem;
use App\Models\User;
use App\Support\Numbers;
use App\Support\PageInfo;
use App\Support\QueryRow;
use App\Tracking\BellEntryKind;
use App\Tracking\Data\BellListFilters;
use App\Users\Data\FollowEntry;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\App;
use LogicException;

/**
 * The following panel of a user view — the inspection page of the bell:
 * everything they follow or mute by hand, titles, people and the titles
 * auto-followed through them, as one grid, newest first, filterable by kind
 * and type. Matrix-derived tracking (backlog, watching…) deliberately stays
 * off it: those live where they are managed.
 */
final readonly class BuildFollowingPanel
{
    private const PER_PAGE = 30;

    /** Followed creators are not media types; they lead the type filter. */
    private const CREATOR_TYPES = [BellEntryKind::Person, BellEntryKind::Company, BellEntryKind::Collection];

    public function __construct(
        private MediaTypeRegistry $types,
        private MediaItemSummaries $cards,
    ) {}

    /**
     * @return array{
     *     entries: list<FollowEntry>,
     *     titles: list<MediaSummary>,
     *     types: list<TypeCount>,
     *     activeType: string|null,
     *     kinds: list<array{kind: string, count: int}>,
     *     activeKind: string|null,
     *     pagination: PageInfo,
     * }
     */
    public function execute(User $user, BellListFilters $filters, int $page): array
    {
        $entries = collect($this->entries($user));

        // The kind filter answers "what did I silence?" as much as "what am I
        // following?" — its counts come from the full set, like the type ones.
        /** @var array<string, int> $kindCounts */
        $kindCounts = $entries->countBy(fn (FollowEntry $entry) => $entry->kind->value)->all();
        $kinds = array_values(array_map(
            fn (BellEntryKind $kind): array => ['kind' => $kind->value, 'count' => $kindCounts[$kind->value]],
            array_filter(BellEntryKind::cases(), fn (BellEntryKind $kind): bool => isset($kindCounts[$kind->value])),
        ));

        $activeKind = $filters->kind !== null && isset($kindCounts[$filters->kind->value]) ? $filters->kind : null;

        if ($activeKind !== null) {
            $entries = $entries->filter(fn (FollowEntry $entry) => $entry->kind === $activeKind)->values();
        }

        // Type options count BEFORE the type filter, so switching types stays
        // possible (within the active kind). Creators first, then media types
        // in the registry's order.
        /** @var array<string, int> $counts */
        $counts = $entries->countBy(fn (FollowEntry $entry) => $entry->media->type)->all();
        $creatorTypes = array_map(fn (BellEntryKind $kind): string => $kind->value, self::CREATOR_TYPES);
        $types = array_map(
            fn (string $type): TypeCount => new TypeCount($type, $counts[$type]),
            [...array_values(array_intersect($creatorTypes, array_keys($counts))), ...$this->types->ordered(array_keys($counts))],
        );

        $activeType = $filters->type !== null && isset($counts[$filters->type]) ? $filters->type : null;

        if ($activeType !== null) {
            $entries = $entries->filter(fn (FollowEntry $entry) => $entry->media->type === $activeType)->values();
        }

        $total = $entries->count();
        $lastPage = max(1, (int) ceil($total / self::PER_PAGE));
        $page = min($page, $lastPage);
        $slice = $entries->slice(($page - 1) * self::PER_PAGE, self::PER_PAGE)->values();

        return [
            'entries' => array_values($slice->all()),
            // People, companies and collections carry no media state — only
            // real titles do.
            'titles' => array_values($slice
                ->map(fn (FollowEntry $entry): MediaSummary => $entry->media)
                ->reject(fn (MediaSummary $media): bool => in_array($media->type, $creatorTypes, true))
                ->all()),
            'types' => $types,
            'activeType' => $activeType,
            'kinds' => $kinds,
            'activeKind' => $activeKind?->value,
            'pagination' => new PageInfo($page, $lastPage, $total),
        ];
    }

    /**
     * Every bell row as a flat entry, newest first: a followed person, a
     * followed studio / publisher / network, a followed provider collection
     * (film saga, book or game series), the user's own bell, one a followed
     * creator rang (with their name), or a muted title.
     *
     * @return list<FollowEntry>
     */
    private function entries(User $user): array
    {
        $follows = $user->follows()
            ->with(['mediaItem.parent.parent', 'person', 'company', 'list', 'viaPeople', 'viaCompanies', 'viaLists'])
            ->latest('id')
            ->get();

        $covers = $this->collectionCovers(Numbers::ints($follows->pluck('list_id')));

        return array_values($follows
            ->map(function (Follow $follow) use ($covers): ?FollowEntry {
                if ($follow->person !== null && $follow->mode === FollowMode::Follow) {
                    return new FollowEntry(
                        media: new MediaSummary(
                            type: BellEntryKind::Person->value,
                            ref: $follow->person->provider_ref,
                            provider: $follow->person->provider,
                            title: $follow->person->name,
                            posterPath: $follow->person->profile_path,
                        ),
                        kind: BellEntryKind::Person,
                        personName: null,
                    );
                }

                if ($follow->company !== null && $follow->mode === FollowMode::Follow) {
                    return new FollowEntry(
                        media: new MediaSummary(
                            type: BellEntryKind::Company->value,
                            ref: $follow->company->provider_ref,
                            provider: $follow->company->provider,
                            title: $follow->company->name,
                            posterPath: $follow->company->logo_path,
                            creatorKind: CreatorKind::from($follow->company->kind),
                            makes: $this->types->rootServedBy($follow->company->provider),
                        ),
                        kind: BellEntryKind::Company,
                        personName: null,
                    );
                }

                // A collection has no media state of its own — the card is a
                // link to its page. Unlike people and companies it keeps its
                // muted rows visible: muting a series is a lasting "not
                // interested", and the page is where it gets undone.
                if ($follow->list !== null) {
                    $cover = $covers[$follow->list_id] ?? ['posterPath' => null, 'count' => 0];
                    // A bell on a list is only ever on a collection.
                    $source = $follow->list->collectionSource() ?? throw new LogicException('A followed list is a collection.');

                    return new FollowEntry(
                        media: new MediaSummary(
                            type: BellEntryKind::Collection->value,
                            ref: $source['ref'],
                            provider: $source['provider'],
                            title: $follow->list->displayTitle(),
                            posterPath: $cover['posterPath'],
                            extra: ['itemCount' => $cover['count']],
                        ),
                        kind: $follow->mode === FollowMode::Mute ? BellEntryKind::Muted : BellEntryKind::Collection,
                        personName: null,
                    );
                }

                if ($follow->mediaItem === null) {
                    return null;
                }

                $viaNames = $follow->sponsorNames();

                return new FollowEntry(
                    media: $this->cards->inContext($follow->mediaItem),
                    kind: match (true) {
                        $follow->mode === FollowMode::Mute => BellEntryKind::Muted,
                        $viaNames !== '' => BellEntryKind::Via,
                        default => BellEntryKind::Manual,
                    },
                    personName: $viaNames !== '' ? $viaNames : null,
                );
            })
            ->filter()
            ->all());
    }

    /**
     * Cover poster (the collection's first part) and size for every followed
     * collection, in one query — a collection list carries only a backdrop,
     * and the followed grid is a poster grid.
     *
     * @param  list<int>  $listIds
     * @return array<int, array{posterPath: string|null, count: int}>
     */
    private function collectionCovers(array $listIds): array
    {
        if ($listIds === []) {
            return [];
        }

        return MediaItem::query()
            ->join('list_items', 'list_items.media_item_id', '=', 'media_items.id')
            ->whereIn('list_items.list_id', $listIds)
            ->orderBy('list_items.position')
            ->get(['list_items.list_id', 'media_items.poster_path', 'media_items.localized_titles'])
            ->mapToGroups(fn (MediaItem $item): array => [QueryRow::int($item, 'list_id') => $item])
            ->map(fn (Collection $items) => [
                'posterPath' => $items->map(fn (MediaItem $item): ?string => $item->posterIn(App::getLocale()))->filter()->first(),
                'count' => $items->count(),
            ])
            ->all();
    }
}
