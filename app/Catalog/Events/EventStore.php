<?php

namespace App\Catalog\Events;

use App\Catalog\CardSnapshot;
use App\Catalog\Data\EventData;
use App\Catalog\Data\MediaSummary;
use App\Models\CatalogEvent;
use App\Models\CatalogEventTitle;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * The one reader and writer of the events a medium's providers list and of
 * the titles they showed. An event is a fact about the world, the same for
 * every user; whether one sees it is the tracking side's question.
 */
final readonly class EventStore
{
    /**
     * Each event as its provider now lists it, its titles replaced.
     *
     * @param  list<EventData>  $events
     */
    public function store(string $provider, array $events): void
    {
        foreach ($events as $data) {
            DB::transaction(function () use ($provider, $data): void {
                $event = CatalogEvent::query()->updateOrCreate(['provider' => $provider, 'ref' => $data->ref], [
                    'name' => $data->name,
                    'description' => $data->description,
                    'starts_at' => $data->startsAt,
                    'ends_at' => $data->endsAt,
                    'time_zone' => $data->timeZone,
                    'live_stream_url' => $data->liveStreamUrl,
                    'logo_path' => $data->logoPath,
                ]);

                CatalogEventTitle::query()->where('catalog_event_id', $event->id)->delete();

                $rows = [];

                foreach ($data->titles as $title) {
                    // A title the provider lists twice keeps its first place.
                    $rows[$title->key()] ??= [
                        ...CardSnapshot::columns($title),
                        'card' => json_encode(CardSnapshot::columns($title)['card'], JSON_THROW_ON_ERROR),
                        'catalog_event_id' => $event->id,
                        'position' => count($rows),
                        'votes' => $title->votes,
                    ];
                }

                CatalogEventTitle::query()->insert(array_values($rows));
            });
        }
    }

    /**
     * Every kept event, for a reader to narrow (who sees which is the tracking side's).
     *
     * @return Builder<CatalogEvent>
     */
    public function kept(): Builder
    {
        return CatalogEvent::query();
    }

    public function find(string $provider, string $ref): ?CatalogEvent
    {
        return CatalogEvent::query()->where('provider', $provider)->where('ref', $ref)->first();
    }

    /** @return list<MediaSummary> the titles the event showed, in the provider's order */
    public function titlesOf(CatalogEvent $event): array
    {
        return array_values($event->titles()->get()->map(CardSnapshot::of(...))->all());
    }
}
