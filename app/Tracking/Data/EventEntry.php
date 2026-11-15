<?php

namespace App\Tracking\Data;

use App\Catalog\Data\EventData;
use App\Models\CatalogEvent;
use Carbon\CarbonInterface;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * An event on a user's calendar or behind their "live" button: when it runs
 * (instants, read in the viewer's zone), where it streams, its page.
 */
#[TypeScript]
final readonly class EventEntry
{
    public function __construct(
        public string $provider,
        public string $ref,
        public string $name,
        /** ISO 8601 instant */
        public string $startsAt,
        /** ISO 8601 instant: the provider's end, or the start plus the usual length of a show where it names none */
        public string $endsAt,
        public ?string $liveStreamUrl,
        public ?string $logoPath,
    ) {}

    public static function of(CatalogEvent $event): self
    {
        return new self(
            provider: $event->provider,
            ref: $event->ref,
            name: $event->name,
            startsAt: $event->starts_at->toIso8601String(),
            endsAt: self::endOf($event->starts_at, $event->ends_at)->toIso8601String(),
            liveStreamUrl: $event->live_stream_url,
            logoPath: $event->logo_path,
        );
    }

    /** An event as its provider lists it, one not kept locally (long past). */
    public static function fromData(string $provider, EventData $event): self
    {
        return new self(
            provider: $provider,
            ref: $event->ref,
            name: $event->name,
            startsAt: $event->startsAt->toIso8601String(),
            endsAt: self::endOf($event->startsAt, $event->endsAt)->toIso8601String(),
            liveStreamUrl: $event->liveStreamUrl,
            logoPath: $event->logoPath,
        );
    }

    private static function endOf(CarbonInterface $start, ?CarbonInterface $end): CarbonInterface
    {
        return $end ?? $start->copy()->addMinutes(config()->integer('tracker.events.fallback_minutes'));
    }
}
