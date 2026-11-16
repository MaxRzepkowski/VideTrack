<?php

namespace App\Integrations\Import\Data;

use App\Catalog\MediaKind;

/**
 * Which title a row is about, in one of two forms.
 *
 * Direct: the source already speaks our coordinates (a VideTrack backup, a
 * Yamtrack row keyed by tmdb/igdb/mal). Nothing is looked up. A season or an
 * episode the source knows by its show's ref and its numbers comes "below"
 * that root, and the provider composes the ref — the source never builds one.
 *
 * External: the source knows the title by foreign ids, which go through the
 * ExternalIdResolver. An episode's own ids and its show's ids travel apart —
 * reading one as the other is how a stranger's series gets marked watched.
 * A source that accepts a match by name adds a TitleQuery; the resolver
 * itself never guesses from a title. An episode's query names its show,
 * and the season and episode numbers lead down from there; a source that
 * knows an episode only by its title gives the season and that title.
 */
final readonly class MediaSubject
{
    /**
     * @param  array<string, string>  $externalIds
     * @param  array<string, string>  $seriesExternalIds
     */
    private function __construct(
        public string $label,
        public ?string $type = null,
        public ?string $provider = null,
        public ?string $ref = null,
        public ?MediaKind $kind = null,
        public array $externalIds = [],
        public array $seriesExternalIds = [],
        public ?int $season = null,
        public ?int $episode = null,
        public ?TitleQuery $byTitle = null,
        /** @var list<int> numbers from the root down (season, episode); empty when `ref` is the item's own */
        public array $path = [],
        public ?string $episodeTitle = null,
    ) {}

    public static function direct(string $type, string $provider, string $ref, string $label): self
    {
        return new self($label, type: $type, provider: $provider, ref: $ref);
    }

    /**
     * A descendant of a root the source knows directly: a show's ref, then
     * season and episode numbers.
     *
     * @param  list<int>  $path
     */
    public static function below(string $rootType, string $provider, string $rootRef, array $path, string $label): self
    {
        return new self($label, type: $rootType, provider: $provider, ref: $rootRef, path: $path);
    }

    /**
     * @param  array<string, string>  $externalIds  source => id of the title itself
     * @param  array<string, string>  $seriesExternalIds  the show's ids, for an episode
     * @param  TitleQuery|null  $byTitle  tried only when no id leads anywhere
     * @param  string|null  $episodeTitle  an episode's own title, for a source
     *                                     that numbers no episodes
     */
    public static function external(
        MediaKind $kind,
        array $externalIds,
        string $label,
        array $seriesExternalIds = [],
        ?int $season = null,
        ?int $episode = null,
        ?TitleQuery $byTitle = null,
        ?string $episodeTitle = null,
    ): self {
        return new self(
            $label,
            kind: $kind,
            externalIds: $externalIds,
            seriesExternalIds: $seriesExternalIds,
            season: $season,
            episode: $episode,
            byTitle: $byTitle,
            episodeTitle: $episodeTitle,
        );
    }

    public function isDirect(): bool
    {
        return $this->type !== null && $this->provider !== null && $this->ref !== null;
    }
}
