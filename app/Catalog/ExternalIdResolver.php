<?php

namespace App\Catalog;

use App\Catalog\Contracts\SupportsIsbnLookup;
use App\Catalog\Data\ResolvedMedia;
use App\Models\ExternalIdMapping;
use Illuminate\Support\Facades\Cache;

/**
 * "A Jellyfin server says imdb tt0133093 just finished" → `movie:603`.
 *
 * Shared by incoming webhooks and imports, which is why it lives in the
 * media core rather than in the scrobble package. Strategies run in
 * order of certainty: an id we already speak costs nothing, a foreign id
 * costs one provider call the first time and nothing ever after.
 *
 * Deliberately no title matching. Guessing a title from a string is a
 * per-source decision with per-source consequences, so the sources that want
 * it bring their own; the framework only ever resolves identifiers.
 */
final readonly class ExternalIdResolver
{
    /** Remember a fruitless lookup for a day so we stop asking. */
    private const MISS_TTL_HOURS = 24;

    public function __construct(
        private MediaTypeRegistry $types,
        private ProviderRegistry $providers,
        private ExternalRefLookup $externalRefs,
        private HierarchyPath $hierarchy,
    ) {}

    /**
     * @param  array<string, string>  $externalIds  source => id, naming the item
     *                                              the event is about
     * @param  MediaKind  $mediaKind  what the sender thinks it is: movie, show,
     *                                episode, game, book, manga…
     * @param  string  $locale  the language a title first met is persisted in
     * @param  array<string, string>  $seriesExternalIds  the show's own ids, on an
     *                                                    episode whose sender knows them
     *
     * @throws ProviderException|ProviderRejected when no id could be asked about and none was known
     */
    public function resolve(
        array $externalIds,
        MediaKind $mediaKind,
        string $locale,
        ?int $season = null,
        ?int $episode = null,
        array $seriesExternalIds = [],
    ): ?ResolvedMedia {
        $ids = $this->usable($externalIds);

        if ($mediaKind === MediaKind::Episode) {
            return $this->resolveEpisode($ids, $this->usable($seriesExternalIds), $season, $episode, $locale);
        }

        // A season has no ids of its own anywhere; it is its show's, numbered.
        if ($mediaKind === MediaKind::Season) {
            $show = $season === null ? null : $this->first($this->usable($seriesExternalIds), MediaKind::Show, $locale);

            return $show === null ? null : $this->hierarchy->descend($show->type, $show->ref, [$season]);
        }

        return $this->first($ids, $mediaKind, $locale);
    }

    /**
     * Two roads down to one episode, exact one first.
     *
     * Media servers hand out the ids of the episode *file*, and an episode's
     * tmdb or tvdb number has nothing to do with its show's — reading one as
     * the other is how integrations end up marking a stranger's series as
     * watched. So the episode's own id is only ever asked about as an
     * episode, and the descent by season and number only ever starts from ids
     * the sender explicitly called the show's.
     *
     * @param  array<string, string>  $ids
     * @param  array<string, string>  $seriesIds
     */
    private function resolveEpisode(
        array $ids,
        array $seriesIds,
        ?int $season,
        ?int $episode,
        string $locale,
    ): ?ResolvedMedia {
        // The episode's own ids failing to be asked about still leaves the
        // road through the show; the failure only stands if that one finds
        // nothing either.
        $unreachable = null;

        try {
            $exact = $this->first($ids, MediaKind::Episode, $locale);
        } catch (ProviderException|ProviderRejected $e) {
            [$exact, $unreachable] = [null, $e];
        }

        // What an id leads to is checked, not assumed: one that leads to a
        // show would have its ref read as an episode's, which it is not.
        if ($exact !== null && $exact->type === MediaKind::Episode->value) {
            return $exact;
        }

        $show = $this->first($seriesIds, MediaKind::Show, $locale);
        $found = $show === null ? null : $this->hierarchy->episode($show, $season, $episode);

        if ($found === null && $unreachable !== null) {
            throw $unreachable;
        }

        return $found;
    }

    /**
     * The first strategy with something to say about these ids.
     *
     * @param  array<string, string>  $ids
     */
    private function first(array $ids, MediaKind $mediaKind, string $locale): ?ResolvedMedia
    {
        $native = $this->nativeSource($mediaKind);

        // Our own provider's id, handed to us verbatim: zero HTTP, and
        // nothing to remember — the mapping is the id itself.
        if ($native !== null && isset($ids[$native])) {
            return new ResolvedMedia($mediaKind->value, $ids[$native]);
        }

        $unreachable = null;

        foreach ($this->sourcesFor($mediaKind) as $source) {
            try {
                $resolved = isset($ids[$source->value]) ? $this->through($source, $ids[$source->value], $mediaKind, $locale) : null;
            } catch (ProviderException|ProviderRejected $e) {
                [$resolved, $unreachable] = [null, $e];
            }

            if ($resolved !== null) {
                return $resolved;
            }
        }

        // "Nothing knows these ids" and "nobody could be asked" are different
        // answers: a scrobble retries the second, an import reports it.
        if ($unreachable !== null) {
            throw $unreachable;
        }

        return null;
    }

    /**
     * The foreign catalogues asked about for this kind, most certain first:
     * whatever its provider translates, in the provider's own order, and an
     * ISBN for a book.
     *
     * @return list<ExternalIdSource>
     */
    private function sourcesFor(MediaKind $mediaKind): array
    {
        $sources = $this->externalRefs->sourcesFor($mediaKind->value);

        return $mediaKind === MediaKind::Book ? [...$sources, ExternalIdSource::Isbn] : $sources;
    }

    /**
     * The ids worth asking about: not blank, and — since an id goes into a
     * provider's address — shaped like a ref (an ISBN excepted: it is asked
     * about as a variable of a query, never as part of a path, so it keeps
     * its own spelling).
     *
     * @param  array<string, string>  $ids
     * @return array<string, string>
     */
    private function usable(array $ids): array
    {
        return array_filter(
            $ids,
            fn (string $id, string $source): bool => trim($id) !== '' && ($source === ExternalIdSource::Isbn->value || MediaRef::holds($id)),
            ARRAY_FILTER_USE_BOTH,
        );
    }

    /**
     * One foreign id, asked about through both caches: the durable mapping
     * table first (a bulk import asks about the same ids thousands of times),
     * then a short-lived negative cache so a genuinely unknown id is not
     * re-asked on every delivery.
     */
    private function through(ExternalIdSource $source, string $id, MediaKind $mediaKind, string $locale): ?ResolvedMedia
    {
        $mapped = ExternalIdMapping::query()
            ->where('source', $source)
            ->where('external_ref', $id)
            ->where('media_kind', $mediaKind)
            ->first();

        if ($mapped !== null) {
            return new ResolvedMedia($mapped->type, $mapped->provider_ref);
        }

        $missKey = "extid-miss:{$source->value}:{$id}:{$mediaKind->value}";

        if (Cache::get($missKey) === true) {
            return null;
        }

        // A provider that could not be asked (ProviderException,
        // ProviderRejected) is not a miss: it goes up unremembered, and the
        // caller decides — a scrobble retries, an import loses the entry.
        try {
            $resolved = $source === ExternalIdSource::Isbn ? $this->viaIsbn($id, $mediaKind, $locale) : $this->viaProvider($source, $id, $mediaKind);
        } catch (ItemNotFoundException) {
            // The provider answered: it has no such thing. A miss like any other.
            $resolved = null;
        }

        if ($resolved === null) {
            Cache::put($missKey, true, now()->addHours(self::MISS_TTL_HOURS));

            return null;
        }

        ExternalIdMapping::query()->updateOrCreate(
            ['source' => $source, 'external_ref' => $id, 'media_kind' => $mediaKind],
            [
                'type' => $resolved->type,
                'provider' => $this->types->get($resolved->type)->provider,
                'provider_ref' => $resolved->ref,
            ],
        );

        return $resolved;
    }

    /**
     * Which id source already speaks our namespace for this kind: the
     * provider serving the type of that name, when this instance serves it.
     * A root type's ref is its provider's bare id — tmdb for films, igdb for
     * games, mal for manga — so the id is the answer.
     *
     * Not below a root: our episode ref is show:season:number, and an
     * episode's bare tmdb id is none of those three.
     */
    private function nativeSource(MediaKind $mediaKind): ?string
    {
        if (! $this->types->has($mediaKind->value)) {
            return null;
        }

        $type = $this->types->get($mediaKind->value);

        return $type->isRoot() ? $type->provider : null;
    }

    /**
     * A foreign catalogue's id, translated by whichever of our providers
     * speaks that catalogue for the type of the kind's name — one id can only
     * be one thing. The foreign catalogues number episodes in their own
     * right, and so does the index asked: an episode id answers as an
     * episode. A type whose provider speaks no foreign catalogue is never
     * asked.
     */
    private function viaProvider(ExternalIdSource $source, string $id, MediaKind $mediaKind): ?ResolvedMedia
    {
        $type = $mediaKind->value;

        if (! $this->types->has($type) || ! in_array($source, $this->externalRefs->sourcesFor($type), true)) {
            return null;
        }

        $ref = $this->externalRefs->refFor($type, $source, $id);

        return $ref === null ? null : new ResolvedMedia($type, $ref);
    }

    private function viaIsbn(string $isbn, MediaKind $mediaKind, string $locale): ?ResolvedMedia
    {
        if ($mediaKind !== MediaKind::Book || ! $this->types->has($mediaKind->value)) {
            return null;
        }

        $provider = $this->providers->firstSupporting(SupportsIsbnLookup::class);

        if (! $provider instanceof SupportsIsbnLookup) {
            return null;
        }

        // The provider speaks ISBN-13; an ISBN-10 is the same book in the older spelling.
        $thirteen = Isbn::thirteen($isbn);

        if ($thirteen === null) {
            return null;
        }

        $book = $provider->bookByIsbns([$thirteen], $locale);

        return $book === null ? null : new ResolvedMedia($book->type, $book->ref);
    }
}
