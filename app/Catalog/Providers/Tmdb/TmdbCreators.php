<?php

namespace App\Catalog\Providers\Tmdb;

use App\Catalog\CatalogueShelf;
use App\Catalog\CompanyRole;
use App\Catalog\Data\CompanyCredit;
use App\Catalog\Data\CompanyDetails;
use App\Catalog\Data\CreditPage;
use App\Catalog\Data\MediaSummary;
use App\Catalog\Data\Paginated;
use App\Catalog\Data\PersonCredit;
use App\Catalog\Data\PersonDetails;
use App\Catalog\Department;
use App\Catalog\MatureContent;
use App\Support\Payload;

/**
 * People, studios and networks as TMDB serves them: their pages, their
 * catalogues in chunks, and the popular people.
 */
final readonly class TmdbCreators
{
    /**
     * Discover pages fetched per chunk of a company's or network's catalogue —
     * twenty titles each, so a chunk is a hundred per endpoint. Not a cap: the
     * page keeps asking for chunks until the provider runs out.
     */
    private const CATALOGUE_PAGES_PER_CHUNK = 5;

    /** A person's photos kept, best rated first: a gallery, not the whole archive. */
    public const PHOTOS_KEPT = 30;

    public function __construct(
        private TmdbClient $client,
        private TmdbPayload $payload,
    ) {}

    public function person(string $ref, string $locale): PersonDetails
    {
        $data = $this->client->get("person/{$ref}", [
            'language' => $this->payload->language($locale),
            'append_to_response' => 'combined_credits,external_ids,translations,images',
        ], $this->payload->ttl('details'));

        $credits = [];

        foreach (['cast', 'crew'] as $group) {
            foreach (Payload::rows(data_get($data, "combined_credits.{$group}")) as $entry) {
                $media = match ($entry['media_type'] ?? null) {
                    'movie' => $this->payload->movieSummary($entry),
                    'tv' => $this->payload->showSummary($entry),
                    default => null,
                };

                if ($media === null) {
                    continue;
                }

                $credits[] = new PersonCredit(
                    media: $media,
                    department: $group === 'cast' ? Department::Acting : $this->payload->department($entry),
                    role: Payload::string($entry, $group === 'cast' ? 'character' : 'job'),
                    popularity: Payload::float($entry, 'popularity') ?? 0.0,
                    billing: $group === 'cast' ? Payload::int($entry, 'order') : null,
                );
            }
        }

        usort($credits, fn (PersonCredit $a, PersonCredit $b) => $b->popularity <=> $a->popularity);

        return new PersonDetails(
            ref: $this->payload->ref($data),
            name: Payload::text($data, 'name'),
            profilePath: Payload::string($data, 'profile_path'),
            biography: $this->payload->translated($data, 'biography', $locale),
            knownForDepartment: $this->payload->knownForDepartment($data),
            birthday: Payload::string($data, 'birthday'),
            deathday: Payload::string($data, 'deathday'),
            placeOfBirth: Payload::string($data, 'place_of_birth'),
            alsoKnownAs: Payload::strings($data['also_known_as'] ?? null),
            photos: $this->personPhotos($data),
            externalLinks: $this->payload->externalLinks($data, 'person', 'person'),
            credits: $credits,
        );
    }

    /**
     * A TV network page (HBO, Netflix): its core record plus a showography.
     * Reuses CompanyDetails — a network is a followable creator entity like a
     * studio, with a logo and the shows that air on it instead of a filmography.
     */
    public function network(string $ref, string $locale, MatureContent $mature): CompanyDetails
    {
        $data = $this->client->get("network/{$ref}", [], $this->payload->ttl('details'));

        $homepage = Payload::string($data, 'homepage');
        $first = $this->networkCredits($ref, $locale, $mature, 1);

        return new CompanyDetails(
            ref: Payload::id($data['id'] ?? null) ?? $ref,
            name: Payload::text($data, 'name'),
            logoPath: Payload::string($data, 'logo_path'),
            country: Payload::string($data, 'origin_country'),
            externalLinks: $homepage !== null ? ['homepage' => $homepage] : [],
            credits: $first->credits,
            hasMoreCredits: $first->hasMore,
        );
    }

    /**
     * A network's showography — upcoming shows first (soonest premiere), then
     * the popular back catalogue, deduped. The show analogue of a company's
     * ludography, and the same source the follow sync reads to auto-follow a
     * network's upcoming premieres.
     *
     * Netflix alone carries ~2900 shows and discover pays out twenty at a
     * time, so the showography arrives in chunks; the page asks for the next
     * one when the user reaches the end of this one.
     */
    public function networkCredits(string $ref, string $locale, MatureContent $mature, int $page): CreditPage
    {
        $credits = [];
        $seen = [];

        // The upcoming slice rides with the first chunk only: it is short, it
        // is what the follow sweep reads, and sorted by premiere it would
        // never surface from under the popular back catalogue.
        $upcoming = $page === 1 ? $this->discoverRows('tv', $locale, [
            'with_networks' => $ref,
            'sort_by' => 'first_air_date.asc',
            'first_air_date.gte' => now()->format('Y-m-d'),
        ], $mature, 1, 1) : ['rows' => [], 'hasMore' => false];

        $catalogue = $this->discoverRows('tv', $locale, [
            'with_networks' => $ref,
            'sort_by' => 'popularity.desc',
        ], $mature, $page, self::CATALOGUE_PAGES_PER_CHUNK);

        foreach ([...$upcoming['rows'], ...$catalogue['rows']] as $show) {
            $id = Payload::id($show['id'] ?? null);

            if ($id === null || isset($seen[$id])) {
                continue;
            }

            $seen[$id] = true;
            $credits[] = new CompanyCredit(media: $this->payload->showSummary($show), role: CompanyRole::Network, category: CatalogueShelf::Show);
        }

        return new CreditPage($credits, $catalogue['hasMore']);
    }

    /**
     * CATALOGUE_PAGES_PER_CHUNK discover pages in a row, starting where chunk
     * `$chunk` begins — the unit every creator catalogue streams in.
     *
     * @param  array<string, bool|int|string>  $params
     * @return array{rows: list<array<array-key, mixed>>, hasMore: bool}
     */
    private function discoverRows(string $endpoint, string $locale, array $params, MatureContent $mature, int $chunk, int $pagesPerChunk): array
    {
        $rows = [];
        $hasMore = false;
        $first = (max(1, $chunk) - 1) * $pagesPerChunk + 1;

        for ($page = $first; $page < $first + $pagesPerChunk && $page <= TmdbPayload::MAX_PAGES; $page++) {
            $data = $this->client->get("discover/{$endpoint}", [
                ...$params,
                'language' => $this->payload->language($locale),
                'include_adult' => $mature->allowsAdult(),
                'page' => $page,
            ], $this->payload->ttl('listing'), 'results');

            $rows = [...$rows, ...Payload::list($data['results'] ?? null)];

            // TMDB refuses pages past its own ceiling, so a catalogue deeper
            // than that ends where the ceiling does.
            $hasMore = $page < min(Payload::int($data, 'total_pages') ?? 1, TmdbPayload::MAX_PAGES);

            if (! $hasMore) {
                break;
            }
        }

        return ['rows' => $rows, 'hasMore' => $hasMore];
    }

    /**
     * A production company (studio) page: HBO, A24, Legendary… — the film/TV
     * analogue of an IGDB game company. Reuses CompanyDetails: a logo, a country
     * and a filmography of the movies and shows it produced (role `Studio`).
     */
    public function company(string $ref, string $locale, MatureContent $mature): CompanyDetails
    {
        $data = $this->client->get("company/{$ref}", [], $this->payload->ttl('details'));

        $parent = Payload::map($data['parent_company'] ?? null);
        $homepage = Payload::string($data, 'homepage');
        $first = $this->companyCredits($ref, $locale, $mature, 1);

        return new CompanyDetails(
            ref: Payload::id($data['id'] ?? null) ?? $ref,
            name: Payload::text($data, 'name'),
            logoPath: Payload::string($data, 'logo_path'),
            description: Payload::string($data, 'description'),
            country: Payload::string($data, 'origin_country'),
            parentName: Payload::string($parent, 'name'),
            parentRef: Payload::id($parent['id'] ?? null),
            externalLinks: $homepage !== null ? ['homepage' => $homepage] : [],
            credits: $first->credits,
            hasMoreCredits: $first->hasMore,
        );
    }

    /**
     * A studio's filmography — upcoming titles first, then the popular back
     * catalogue — across both movies and shows, deduped. The film/TV analogue
     * of an IGDB company's ludography and the source the follow sync reads to
     * auto-follow a studio's upcoming releases (role `Studio`, category
     * `movie` / `show`).
     *
     * Warner Bros has over three thousand films on TMDB, so the filmography
     * streams in chunks the same way a network's showography does; the shelf
     * ends when both endpoints run out.
     */
    public function companyCredits(string $ref, string $locale, MatureContent $mature, int $page): CreditPage
    {
        $credits = [];
        $hasMore = false;

        foreach (['movie', 'tv'] as $endpoint) {
            $shelf = $endpoint === 'movie' ? CatalogueShelf::Movie : CatalogueShelf::Show;
            $dateField = $endpoint === 'movie' ? 'primary_release_date' : 'first_air_date';

            $upcoming = $page === 1 ? $this->discoverRows($endpoint, $locale, [
                'with_companies' => $ref,
                'sort_by' => "{$dateField}.asc",
                "{$dateField}.gte" => now()->format('Y-m-d'),
            ], $mature, 1, 1) : ['rows' => [], 'hasMore' => false];

            $catalogue = $this->discoverRows($endpoint, $locale, [
                'with_companies' => $ref,
                'sort_by' => 'popularity.desc',
            ], $mature, $page, self::CATALOGUE_PAGES_PER_CHUNK);

            $hasMore = $hasMore || $catalogue['hasMore'];
            $seen = [];

            foreach ([...$upcoming['rows'], ...$catalogue['rows']] as $item) {
                $id = Payload::id($item['id'] ?? null);

                if ($id === null || isset($seen[$id])) {
                    continue;
                }

                $seen[$id] = true;
                $summary = $endpoint === 'movie' ? $this->payload->movieSummary($item) : $this->payload->showSummary($item);
                $credits[] = new CompanyCredit(media: $summary, role: CompanyRole::Studio, category: $shelf);
            }
        }

        return new CreditPage($credits, $hasMore);
    }

    /**
     * The `person/popular` catalogue, paginated exactly like the filtered
     * discovery listings so the page renders it with the machinery it has.
     *
     * Deliberately not `trending/person` — the surface is named "popular", and
     * mixing two rankings into one paginated list makes its page numbers lie.
     *
     * @return Paginated<MediaSummary>
     */
    public function popularPeople(string $locale, int $page = 1): Paginated
    {
        // A page past TMDB's own ceiling answers with an empty list rather
        // than an error, so the ceiling is applied here (as in
        // discoverFiltered); the caller learns the real last page from
        // `totalPages`. The cache guard hangs on `page`, not `results`: an
        // empty page is a legitimate answer at a high number.
        $data = $this->client->get('person/popular', [
            'language' => $this->payload->language($locale),
            'page' => min(max(1, $page), TmdbPayload::MAX_PAGES),
        ], $this->payload->ttl('listing'), 'page');

        $items = [];

        foreach (Payload::rows($data['results'] ?? null) as $result) {
            // No portrait → nothing to show on a card; skip like the search.
            if (Payload::string($result, 'profile_path') === null) {
                continue;
            }

            $items[] = $this->payload->personSummary($result);
        }

        return $this->payload->paginated($data, $items);
    }

    /**
     * @param  array<array-key, mixed>  $data
     * @return list<string>
     */
    private function personPhotos(array $data): array
    {
        $photos = [];

        foreach (Payload::list(data_get($data, 'images.profiles')) as $profile) {
            $path = Payload::string($profile, 'file_path');

            if ($path !== null) {
                $photos[] = ['path' => $path, 'rating' => Payload::float($profile, 'vote_average') ?? 0.0];
            }
        }

        usort($photos, fn (array $a, array $b): int => $b['rating'] <=> $a['rating']);

        return array_column(array_slice($photos, 0, self::PHOTOS_KEPT), 'path');
    }
}
