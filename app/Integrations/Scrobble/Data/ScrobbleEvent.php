<?php

namespace App\Integrations\Scrobble\Data;

use App\Catalog\MediaKind;
use App\Integrations\Scrobble\ScrobbleEventKind;
use Carbon\CarbonImmutable;

/**
 * One normalized playback event, whatever sent it. A source's whole job is
 * turning its own payload into this; everything downstream — resolving the
 * title, deduplicating, deciding what it means — is the framework's.
 *
 * Serializable as-is into a queued job's payload.
 */
final readonly class ScrobbleEvent
{
    /**
     * @param  MediaKind  $mediaKind  what the source thinks it is, not our type code
     * @param  array<string, string>  $externalIds  a provider's code or an ExternalIdSource => id,
     *                                              naming THIS item: on an episode
     *                                              event they are the episode's own
     *                                              ids, which are a different number
     *                                              from its show's
     * @param  array<string, string>  $seriesExternalIds  the show's ids, when the
     *                                                    sender knows them — an
     *                                                    episode is then placed by
     *                                                    show plus season and number
     * @param  string|null  $title  context for the delivery log and for debugging;
     *                              the framework never matches on it
     * @param  float|null  $progressPercent  0–100
     * @param  int|null  $position  how far in, in the type's own unit (pages,
     *                              minutes, chapters, issues) — for senders
     *                              that count rather than estimate
     * @param  int|null  $rating  1–10; the source normalizes its own scale
     * @param  string|null  $serverUsername  who played it, in the sending
     *                                       server's own namespace — a
     *                                       household's endpoints filter on it
     */
    public function __construct(
        public ScrobbleEventKind $kind,
        public MediaKind $mediaKind,
        public array $externalIds,
        public array $seriesExternalIds = [],
        public ?string $title = null,
        public ?int $year = null,
        public ?int $seasonNumber = null,
        public ?int $episodeNumber = null,
        public ?float $progressPercent = null,
        public ?int $position = null,
        public ?int $rating = null,
        public ?CarbonImmutable $occurredAt = null,
        public ?string $serverUsername = null,
    ) {}

    /** What the delivery log shows the user: "Dune (2021)". */
    public function label(): ?string
    {
        if ($this->title === null) {
            return null;
        }

        return $this->year === null ? $this->title : "{$this->title} ({$this->year})";
    }
}
