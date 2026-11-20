<?php

namespace App\Catalog;

use App\Catalog\Data\MediaSummary;
use App\Catalog\Data\RelatedRelease;
use App\Enums\ReleaseKind;
use App\Models\MediaItem;
use App\Support\Payload;
use Illuminate\Support\Facades\App;

/**
 * @phpstan-import-type CardExtra from MediaSummary
 *
 * Library rows as cards, so locally persisted items travel through the same
 * pipeline as provider results. A card names its title and shows its poster
 * in the reader's language — the app locale every `__()` on the same card is
 * translated in, set once per request; a job writes in the default one.
 */
final readonly class MediaItemSummaries
{
    public function __construct(private ProgressTotals $totals) {}

    /**
     * The row alone, for surfaces that show it among its own kind. A pure
     * mapping of the columns, so it needs no instance.
     *
     * @param  CardExtra  $extra  display fields for the card
     * @param  string|null  $releaseDate  one of the title's releases rather
     *                                    than the row's own date (a calendar
     *                                    entry per platform or per moment)
     * @param  DatePrecision|null  $releasePrecision  how much of that release
     *                                                date is known (an exact
     *                                                day unless said)
     * @param  list<string>  $authors
     */
    public static function summary(
        MediaItem $item,
        array $extra = [],
        ?string $posterPath = null,
        ?string $releaseDate = null,
        ?int $seasonNumber = null,
        ?int $runtime = null,
        ?string $overview = null,
        ?ReleaseKind $releaseKind = null,
        ?string $platform = null,
        ?string $releaseStatus = null,
        ?int $progressTotal = null,
        ?EpisodeType $episodeType = null,
        array $authors = [],
        ?EpisodeBadge $episodeBadge = null,
        ?DatePrecision $releasePrecision = null,
    ): MediaSummary {
        return new MediaSummary(
            type: $item->type,
            ref: $item->provider_ref,
            provider: $item->provider,
            title: $item->titleIn(App::getLocale()),
            year: $item->year,
            posterPath: $posterPath ?? $item->posterIn(App::getLocale()),
            backdropPath: $item->backdrop_path,
            communityRating: $item->community_rating,
            releaseDate: $releaseDate ?? $item->release_date?->toDateString(),
            releaseTime: $item->release_time?->toIso8601String(),
            number: $item->number,
            totalRuntime: $item->total_runtime,
            seasonNumber: $seasonNumber,
            runtime: $runtime,
            overview: $overview,
            releaseKind: $releaseKind,
            platform: $platform,
            releaseStatus: $releaseStatus,
            extra: $extra,
            progressTotal: $progressTotal,
            episodeType: $episodeType,
            authors: $authors,
            episodeBadge: $episodeBadge,
            releasePrecision: $releasePrecision ?? ($releaseDate === null ? $item->release_date_precision : DatePrecision::Day),
        );
    }

    /**
     * A game's release of its own (its DLC, a remake) as a card of its own:
     * it has no row to read, so it names the game it belongs to and shows
     * that game's art.
     *
     * @param  CardExtra  $extra
     */
    public static function related(
        MediaItem $game,
        RelatedRelease $release,
        array $extra = [],
        ?string $releaseDate = null,
        ?string $platform = null,
        ?string $releaseStatus = null,
        ?DatePrecision $releasePrecision = null,
    ): MediaSummary {
        return new MediaSummary(
            type: $game->type,
            ref: $release->ref,
            provider: $game->provider,
            title: $release->title,
            year: Payload::yearOf($release->date),
            posterPath: $release->posterPath,
            backdropPath: $game->backdrop_path,
            releaseDate: $releaseDate ?? $release->date,
            platform: $platform,
            releaseStatus: $releaseStatus,
            extra: ['gameType' => $release->kind->value, 'relatedTo' => $game->titleIn(App::getLocale()), ...$extra],
            releasePrecision: $releasePrecision ?? $release->precision,
        );
    }

    /**
     * A row as one of its parent's children on the parent's own page (a
     * show's seasons, a season's episodes), carrying its runtime and synopsis
     * the way a provider's children do.
     */
    public static function child(MediaItem $child, MediaItem $parent): MediaSummary
    {
        $metadata = ItemMetadata::fromStored($child->metadata);

        return self::summary(
            $child,
            MediaSummary::extraFromStored($metadata->extra),
            seasonNumber: Hierarchy::seasonNumber($parent),
            runtime: $child->runtime,
            overview: $child->overview,
            episodeType: $metadata->episodeType,
            episodeBadge: $metadata->episodeBadge,
        );
    }

    /**
     * The row outside its own hierarchy view (lists, history, feeds): a child
     * names its root, a grandchild also its parent's number and its badges, a
     * reading item the total its counter runs to, and a row without a poster
     * borrows the closest ancestor's.
     *
     * @param  CardExtra  $extra  merged over that context
     */
    public function inContext(
        MediaItem $item,
        array $extra = [],
        ?string $releaseDate = null,
        ?ReleaseKind $releaseKind = null,
    ): MediaSummary {
        // A root never touches the relation. Below one, the caller loads
        // `parent.parent` with the rows — a load here, one item at a time,
        // would be the N+1 that lazy loading is switched off to catch.
        $parent = null;
        $root = null;

        if ($item->parent_id !== null) {
            $parent = $item->parent;
            $root = $parent?->parent;
        }

        // A grandchild (an episode) carries its chip wherever its card goes.
        $metadata = $root === null ? null : ItemMetadata::fromStored($item->metadata);

        return self::summary(
            $item,
            extra: [...$this->context($item, $parent, $root), ...($parent === null ? $this->kindOf($item) : []), ...$extra],
            posterPath: $item->posterIn(App::getLocale()) ?? $parent?->posterIn(App::getLocale()) ?? $root?->posterIn(App::getLocale()),
            releaseDate: $releaseDate,
            seasonNumber: $parent === null ? null : Hierarchy::seasonNumber($parent),
            releaseKind: $releaseKind,
            progressTotal: $this->totals->of($item),
            episodeType: $metadata?->episodeType,
            episodeBadge: $metadata?->episodeBadge,
        );
    }

    /**
     * The row plus, for a reading type, its counter's total under the unit's
     * name. The core and the pages read `progressTotal`; the key also rides
     * in `extra`, where the API contract has it.
     */
    public function withProgress(MediaItem $item): MediaSummary
    {
        return self::summary($item, $this->progress($item), progressTotal: $this->totals->of($item));
    }

    /** @return CardExtra */
    private function context(MediaItem $item, ?MediaItem $parent, ?MediaItem $root): array
    {
        if ($root !== null) {
            return ['showTitle' => $root->titleIn(App::getLocale())];
        }

        if ($parent !== null) {
            return ['showTitle' => $parent->titleIn(App::getLocale())];
        }

        return $this->progress($item);
    }

    /**
     * What kind of release a root's card stands for (a DLC, a remake, a
     * novella), as the provider said when the title was stored: its badge on
     * a list or in a history, as on the provider's own cards.
     *
     * @return array{gameType?: value-of<GameKind>, bookKind?: value-of<BookKind>}
     */
    private function kindOf(MediaItem $item): array
    {
        $extra = MediaSummary::extraFromStored(ItemMetadata::fromStored($item->metadata)->extra);

        return array_filter(['gameType' => $extra['gameType'] ?? null, 'bookKind' => $extra['bookKind'] ?? null]);
    }

    /** @return array<value-of<ProgressUnit>, int> */
    private function progress(MediaItem $item): array
    {
        $unit = $this->totals->unit($item);
        $total = $this->totals->of($item);

        return $unit === null || $total === null ? [] : [$unit->value => $total];
    }
}
