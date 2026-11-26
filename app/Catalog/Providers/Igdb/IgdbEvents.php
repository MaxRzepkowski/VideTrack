<?php

namespace App\Catalog\Providers\Igdb;

use App\Catalog\Data\EventData;
use App\Catalog\ItemNotFoundException;
use App\Support\Payload;
use Carbon\CarbonImmutable;

/**
 * The showcases of the games world IGDB lists (Summer Game Fest, a Nintendo
 * Direct): an event comes in shortly before it airs, its games after.
 */
final readonly class IgdbEvents
{
    /** Apicalypse's page ceiling: IGDB lists some fifteen events a month. */
    private const LIMIT = 500;

    /** More showcases than a game is shown at: the most awaited are shown at some fifteen. */
    private const SHOWING_LIMIT = 100;

    private const FIELDS = 'name, description, start_time, end_time, time_zone, live_stream_url, event_logo.image_id';

    public function __construct(
        private IgdbClient $client,
        private IgdbPayload $payload,
    ) {}

    /** @return list<EventData> */
    public function since(CarbonImmutable $since): array
    {
        $rows = $this->client->query(
            'events',
            'fields '.self::FIELDS.', '.IgdbPayload::summaryFieldsOf('games').'; '
            .'where start_time >= '.$since->getTimestamp().'; sort start_time asc; limit '.self::LIMIT.';',
            // An event and its games change by the day around its airing.
            $this->payload->ttl('listing'),
        );

        return $this->events($rows);
    }

    /** One event with the games it showed, kept locally or not (one long past). */
    public function event(string $ref): EventData
    {
        if (! ctype_digit($ref)) {
            throw new ItemNotFoundException("IGDB has no event [{$ref}].");
        }

        $rows = $this->client->query(
            'events',
            'fields '.self::FIELDS.', '.IgdbPayload::summaryFieldsOf('games')."; where id = {$ref};",
            $this->payload->ttl('details'),
            requireResults: true,
        );

        return $this->events($rows)[0] ?? throw new ItemNotFoundException("IGDB returned no event for [{$ref}].");
    }

    /**
     * Every event that showed a game, the latest first; its games are not asked.
     *
     * @return list<EventData>
     */
    public function showing(string $gameRef): array
    {
        if (! ctype_digit($gameRef)) {
            return [];
        }

        return $this->events($this->client->query(
            'events',
            'fields '.self::FIELDS."; where games = ({$gameRef}); sort start_time desc; limit ".self::SHOWING_LIMIT.';',
            $this->payload->ttl('details'),
        ));
    }

    /** @return list<EventData> */
    private function events(mixed $rows): array
    {
        $events = [];

        foreach (Payload::rows($rows) as $row) {
            $start = Payload::epochSeconds($row['start_time'] ?? null);
            $end = Payload::epochSeconds($row['end_time'] ?? null);
            $name = Payload::string($row, 'name');

            if ($start === null || $name === null) {
                continue;
            }

            $events[] = new EventData(
                ref: $this->payload->ref($row),
                name: $name,
                startsAt: $start,
                // An end before the start is no end the page can say.
                endsAt: $end === null || $end <= $start ? null : $end,
                description: Payload::string($row, 'description'),
                timeZone: Payload::string($row, 'time_zone'),
                liveStreamUrl: $this->web(Payload::string($row, 'live_stream_url')),
                // The event's key art (16:9), large enough for its page's header.
                logoPath: $this->payload->imageUrl($row, 'event_logo', 'screenshot_big'),
                titles: array_map(fn (array $game) => $this->payload->summary($game), array_values(array_filter(
                    Payload::rows($row['games'] ?? null),
                    fn (array $game): bool => ! empty($game['cover']),
                ))),
            );
        }

        return $events;
    }

    private function web(?string $url): ?string
    {
        return $url !== null && (str_starts_with($url, 'https://') || str_starts_with($url, 'http://')) ? $url : null;
    }
}
