<?php

namespace App\Catalog\Providers\Tmdb;

use App\Catalog\Data\ChangeReport;
use App\Support\Payload;
use Carbon\CarbonImmutable;

/**
 * TMDB's lists of changed films and shows: ids only, a hundred a page, by
 * calendar day, at most fourteen days a query.
 */
final readonly class TmdbChanges
{
    /** The longest window TMDB answers in one query. */
    public const WINDOW_DAYS = 14;

    public function __construct(
        private TmdbClient $client,
        private TmdbPayload $payload,
    ) {}

    /**
     * @param  list<string>  $refs
     */
    public function changedAmong(string $type, array $refs, CarbonImmutable $since): ChangeReport
    {
        // Days are whole and in UTC. Asking from the start of the day $since
        // fell on overlaps the last reading by the hours before it, which is
        // enough: a title refreshed twice costs nothing, while starting a day
        // earlier would double the pages (a day of films is ~120 of them).
        $start = $since->utc()->startOfDay();
        $end = now()->utc()->startOfDay();

        if ($start->diffInDays($end) > self::WINDOW_DAYS) {
            return ChangeReport::unknown();
        }

        if ($refs === []) {
            return ChangeReport::of([]);
        }

        $path = $this->payload->typePath($type, 'movie/changes', 'tv/changes');
        $wanted = array_flip($refs);
        $changed = [];
        $page = 1;

        do {
            $data = $this->client->get($path, [
                'start_date' => $start->toDateString(),
                'end_date' => $end->toDateString(),
                'page' => $page,
            ], $this->payload->ttl('changes'));

            $pages = Payload::positiveInt($data, 'total_pages') ?? 1;

            // Each page costs a request, like asking about one title: past
            // as many pages as we hold titles, asking each is cheaper.
            if ($pages > count($refs) || $pages > TmdbPayload::MAX_PAGES) {
                return ChangeReport::unknown();
            }

            foreach (Payload::list($data['results'] ?? null) as $entry) {
                $id = Payload::id($entry['id'] ?? null);

                if ($id !== null && isset($wanted[$id])) {
                    $changed[$id] = $id;
                }
            }

            $page++;
        } while ($page <= $pages);

        return ChangeReport::of(array_values($changed));
    }
}
