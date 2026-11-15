<?php

namespace App\Catalog\Providers\ComicVine;

use App\Catalog\Contracts\MetadataProvider;
use App\Catalog\Contracts\SupportsChangeFeed;
use App\Catalog\Data\ChangeReport;
use App\Catalog\Data\MediaDetails;
use App\Catalog\Data\MediaSummary;
use App\Catalog\ItemNotFoundException;
use App\Catalog\ProviderException;
use App\Support\Payload;
use App\Support\PlainText;
use Carbon\CarbonImmutable;
use LogicException;

/**
 * ComicVine implementation of the metadata contracts — the `comic` module.
 * A comic is a ComicVine VOLUME (the series, tracked
 * flat with an issue-position counter, as books are;
 * not a per-issue hierarchy: fetching hundreds of issues per volume does not fit ComicVine's 200 req/h budget); refs are
 * numeric volume ids (the API's 4050- prefix stays an internal detail).
 * ComicVine serves neither community ratings nor any popularity / trending
 * signal, so the provider stops at search + details. Covers are absolute
 * ComicVine CDN URLs (`mediaImage` passes `http…` through).
 */
class ComicVineProvider implements MetadataProvider, SupportsChangeFeed
{
    /** Creators a comic names. */
    private const CREATORS_KEPT = 8;

    /** ComicVine's resource-type prefix for volume detail endpoints. */
    private const VOLUME_PREFIX = '4050';

    /** The most volumes ComicVine lists a page. */
    private const PAGE_SIZE = 100;

    /**
     * ComicVine does not say which zone its dates are in, so the window of
     * the list of changes reaches a day past each end: wide enough for any
     * zone, and a volume refreshed twice costs nothing.
     */
    private const CHANGES_MARGIN_DAYS = 1;

    public function __construct(private readonly ComicVineClient $client) {}

    public function code(): string
    {
        return 'comicvine';
    }

    public function search(string $query, string $locale): array
    {
        $data = $this->client->get('/search/', [
            'query' => $query,
            'resources' => 'volume',
            'limit' => 20,
            'field_list' => 'id,name,deck,image,start_year,publisher,count_of_issues',
        ], $this->ttl('search'), requireResults: true);

        $summaries = [];

        foreach (Payload::rows($data) as $result) {
            if ($this->coverUrl($result) !== null) {
                $summaries[] = $this->summary($result);
            }
        }

        return $summaries;
    }

    public function details(string $type, string $ref, string $locale, ?string $region = null): MediaDetails
    {
        // The type registry routes only its own type here; anything else is a bug.
        if ($type !== 'comic') {
            throw new LogicException("ComicVine does not serve details for type [{$type}].");
        }

        // A ref from an import or a webhook may be anything; a bad one names nothing.
        if (! ctype_digit($ref)) {
            throw new ItemNotFoundException("ComicVine has no comic [{$ref}].");
        }

        $data = $this->client->get('/volume/'.self::VOLUME_PREFIX."-{$ref}/", [
            'field_list' => 'id,name,deck,description,image,start_year,publisher,count_of_issues,people,site_detail_url',
        ], $this->ttl('details'), requireResults: true);

        if (! isset($data['id'])) {
            throw new ItemNotFoundException("ComicVine returned no volume for [{$ref}].");
        }

        $site = Payload::string($data, 'site_detail_url');
        $poster = $this->coverUrl($data);

        return new MediaDetails(
            summary: $this->summary($data),
            tagline: Payload::string($data, 'deck'),
            // Descriptions are rich HTML (links, image blocks).
            overview: PlainText::fromHtml($data['description'] ?? null),
            externalLinks: $site === null ? [] : ['comicvine' => $site],
            extra: [
                'creators' => $this->creators($data),
                'publisher' => Payload::string((array) ($data['publisher'] ?? []), 'name'),
                'issues' => Payload::positiveInt($data, 'count_of_issues'),
            ],
            posters: array_filter([$poster]),
        );
    }

    public function changeFeedTypes(): array
    {
        return ['comic'];
    }

    /**
     * None: neither list has been measured to name every change (a type joins
     * only once that is shown), so every volume is asked about in turn.
     */
    public function completeChangeFeedTypes(): array
    {
        return [];
    }

    /**
     * Two of ComicVine's lists, everyone's rather than ours, read page by
     * page: volumes by their date of last update (a renamed or re-described
     * volume) and issues by the date they were added (a volume's new issue,
     * which leaves the volume's own date alone, so most such volumes are
     * missing from the first list). Each page spends the
     * same hourly budget as asking about one volume: past as many pages as
     * we hold volumes, asking each is cheaper.
     */
    public function changedAmong(string $type, array $refs, CarbonImmutable $since): ChangeReport
    {
        if ($refs === []) {
            return ChangeReport::of([]);
        }

        $window = $since->subDays(self::CHANGES_MARGIN_DAYS)->utc()->format('Y-m-d H:i:s')
            .'|'.now()->addDays(self::CHANGES_MARGIN_DAYS)->utc()->format('Y-m-d H:i:s');
        $wanted = array_flip($refs);
        $changed = [];
        $pages = 0;

        // path => [field the window filters on, field to read, where the volume id sits in a row]
        $lists = [
            '/volumes/' => ['date_last_updated', 'id', 'id'],
            '/issues/' => ['date_added', 'volume', 'volume.id'],
        ];

        foreach ($lists as $path => [$filter, $field, $idAt]) {
            $offset = 0;

            do {
                if (++$pages > count($refs)) {
                    return ChangeReport::unknown();
                }

                $rows = $this->client->get($path, [
                    'filter' => "{$filter}:{$window}",
                    'field_list' => $field,
                    'sort' => 'id:asc',
                    'limit' => self::PAGE_SIZE,
                    'offset' => $offset,
                ], $this->ttl('changes'));

                foreach ($rows as $row) {
                    $id = Payload::id(data_get($row, $idAt));

                    if ($id !== null && isset($wanted[$id])) {
                        $changed[$id] = $id;
                    }
                }

                $offset += self::PAGE_SIZE;
            } while (count($rows) === self::PAGE_SIZE);
        }

        return ChangeReport::of(array_values($changed));
    }

    /**
     * One summary out of either shape: a search result or a volume detail
     * payload (same field names).
     *
     * @param  array<array-key, mixed>  $data
     */
    private function summary(array $data): MediaSummary
    {
        $year = Payload::int($data, 'start_year') ?? 0;

        return new MediaSummary(
            type: 'comic',
            ref: Payload::id($data['id'] ?? null) ?? throw ProviderException::missingId('ComicVine'),
            provider: $this->code(),
            title: Payload::text($data, 'name'),
            year: $year > 0 ? $year : null,
            posterPath: $this->coverUrl($data),
            // ComicVine has no full release dates on volumes and no
            // community ratings at all.
            extra: array_filter([
                'issues' => Payload::positiveInt($data, 'count_of_issues'),
                'publisher' => Payload::string((array) ($data['publisher'] ?? []), 'name'),
            ]),
            progressTotal: Payload::positiveInt($data, 'count_of_issues'),
        );
    }

    /**
     * Creator display names, most-credited first (ComicVine orders `people`
     * by issue count already).
     *
     * @param  array<array-key, mixed>  $data
     * @return list<string>
     */
    private function creators(array $data): array
    {
        $names = [];

        foreach ((array) ($data['people'] ?? []) as $person) {
            $name = is_array($person) ? Payload::string($person, 'name') : null;

            if ($name !== null && ! in_array($name, $names, true)) {
                $names[] = $name;
            }
        }

        return array_slice($names, 0, self::CREATORS_KEPT);
    }

    /**
     * Cover URL out of a ComicVine image object: scale_large (`super_url`)
     * fits the 2:3 cards, then the medium scale; the original can be
     * print-resolution huge, so it is the last resort.
     *
     * @param  array<array-key, mixed>  $data
     */
    private function coverUrl(array $data): ?string
    {
        $image = (array) ($data['image'] ?? []);
        $url = $image['super_url'] ?? $image['medium_url'] ?? $image['original_url'] ?? null;

        return is_string($url) && str_starts_with($url, 'http') ? $url : null;
    }

    private function ttl(string $kind): int
    {
        return config()->integer("tracker.comicvine.cache.{$kind}");
    }
}
