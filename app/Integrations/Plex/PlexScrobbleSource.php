<?php

namespace App\Integrations\Plex;

use App\Catalog\ExternalIdSource;
use App\Catalog\MediaKind;
use App\Integrations\Scrobble\Concerns\ReadsPlaybackPayloads;
use App\Integrations\Scrobble\Contracts\ProvidesSamplePayload;
use App\Integrations\Scrobble\Contracts\ScrobbleSource;
use App\Integrations\Scrobble\Data\ScrobbleEvent;
use App\Integrations\Scrobble\Data\SetupStep;
use App\Integrations\Scrobble\ScrobbleEventKind;
use App\Integrations\Scrobble\UnrecognizedPayload;
use Illuminate\Http\Request;

/**
 * Plex Media Server's webhooks. Two things set them apart from the rest.
 *
 * The delivery is multipart/form-data — the JSON sits in a `payload` field
 * next to a thumbnail we have no use for. And Plex decides for itself when
 * something counts as watched: `media.scrobble` fires around ninety percent
 * in, so that event alone marks a title watched and the play, pause and stop
 * events only ever reach the log.
 */
class PlexScrobbleSource implements ProvidesSamplePayload, ScrobbleSource
{
    use ReadsPlaybackPayloads;

    public const CODE = 'plex';

    /** The catalogues Plex names, under both the old and the new agents. */
    private const AGENTS = [
        'themoviedb' => 'tmdb',
        'thetvdb' => ExternalIdSource::Tvdb->value,
        'imdb' => ExternalIdSource::Imdb->value,
    ];

    public function code(): string
    {
        return self::CODE;
    }

    public function name(): string
    {
        return 'Plex';
    }

    public function description(): string
    {
        return __('integrations.what_you_finish_on_your_plex');
    }

    public function parse(Request $request): ?ScrobbleEvent
    {
        $payload = $this->payload($request) ?? throw new UnrecognizedPayload('A Plex delivery without a payload.');
        $event = $this->text($this->field($payload, ['event'])) ?? throw new UnrecognizedPayload('A Plex delivery without an event.');

        $kind = match (mb_strtolower($event)) {
            // Plex has already decided this one was watched.
            'media.scrobble' => ScrobbleEventKind::Complete,
            'media.play', 'media.resume' => ScrobbleEventKind::Start,
            'media.pause' => ScrobbleEventKind::Pause,
            'media.stop' => ScrobbleEventKind::Stop,
            // Plex stars stay in Plex; the row exists so the log shows the
            // server reaching us.
            'media.rate' => ScrobbleEventKind::Rate,
            default => null,
        };

        $mediaKind = match ($this->lowercase($this->field($payload, ['Metadata.type']))) {
            'movie' => MediaKind::Movie,
            'episode' => MediaKind::Episode,
            default => null,
        };

        if ($kind === null || $mediaKind === null) {
            return null;
        }

        $series = $this->text($this->field($payload, ['Metadata.grandparentTitle']));
        $season = $this->number($this->field($payload, ['Metadata.parentIndex']));
        $number = $this->number($this->field($payload, ['Metadata.index']));

        // The new agents list this item's own ids; the old ones encode the
        // show's id in the guid, with the episode's place after it. So the
        // first bag names the episode and the second names its series.
        $own = $this->newAgentIds($this->field($payload, ['Metadata.Guid']));
        $legacy = $this->oldAgentIds($this->field($payload, ['Metadata.guid']));

        return new ScrobbleEvent(
            kind: $kind,
            mediaKind: $mediaKind,
            externalIds: $mediaKind === MediaKind::Episode ? $own : [...$legacy, ...$own],
            seriesExternalIds: $mediaKind === MediaKind::Episode ? $legacy : [],
            title: $mediaKind === MediaKind::Episode
                ? $this->episodeLabel($series, $season, $number)
                : $this->text($this->field($payload, ['Metadata.title'])),
            year: $this->number($this->field($payload, ['Metadata.year'])),
            seasonNumber: $season,
            episodeNumber: $number,
            progressPercent: $kind === ScrobbleEventKind::Stop
                // Both are milliseconds; the ratio is what matters.
                ? $this->percentFromTicks(
                    $this->field($payload, ['Metadata.viewOffset']),
                    $this->field($payload, ['Metadata.duration']),
                )
                : null,
            serverUsername: $this->text($this->field($payload, ['Account.title'])),
        );
    }

    /**
     * The JSON, wherever it came in: a multipart field on a real Plex
     * delivery, or the body itself when something replays one for us.
     *
     * @return array<array-key, mixed>|null
     */
    private function payload(Request $request): ?array
    {
        $raw = $request->input('payload');

        if (is_array($raw)) {
            return $raw;
        }

        if (is_string($raw)) {
            $decoded = json_decode($raw, true);

            return is_array($decoded) ? $decoded : null;
        }

        $body = $this->readPayload($request);

        return isset($body['event']) ? $body : null;
    }

    /**
     * `[{"id": "tmdb://603"}, …]` — one entry per catalogue the new agents
     * matched, naming this very item.
     *
     * @return array<string, string>
     */
    private function newAgentIds(mixed $guids): array
    {
        $ids = [];

        foreach (is_array($guids) ? $guids : [] as $entry) {
            $guid = is_array($entry) ? ($entry['id'] ?? null) : $entry;

            if (! is_string($guid) || ! str_contains($guid, '://')) {
                continue;
            }

            [$source, $id] = explode('://', $guid, 2);

            $ids[$source] = $id;
        }

        return $this->providerIds($ids);
    }

    /**
     * `com.plexapp.agents.thetvdb://121361/1/1?lang=en` — the old agents'
     * single guid, which for an episode names the *show* and then walks
     * down to it. Anime libraries on the Hama agent name neither catalogue
     * we speak, so they fall out here.
     *
     * @return array<string, string>
     */
    private function oldAgentIds(mixed $guid): array
    {
        if (! is_string($guid) || preg_match('#^com\.plexapp\.agents\.([a-z]+)://([^/?]+)#i', $guid, $match) !== 1) {
            return [];
        }

        $source = self::AGENTS[mb_strtolower($match[1])] ?? null;

        return $source === null ? [] : $this->providerIds([$source => $match[2]]);
    }

    public function instructions(): array
    {
        return [
            new SetupStep(
                __('integrations.open_your_plex_account_settings'),
                __('integrations.go_to_plex_tv_settings_webhooks'),
            ),
            new SetupStep(
                __('integrations.add_this_address'),
                __('integrations.choose_add_webhook_paste_the_address'),
            ),
            new SetupStep(
                __('integrations.one_endpoint_per_person'),
                __('integrations.plex_posts_for_every_account_on'),
            ),
            new SetupStep(
                __('integrations.older_libraries_need_their_ids'),
                __('integrations.titles_matched_by_the_legacy_plex'),
            ),
        ];
    }

    public function notice(): ?string
    {
        return __('integrations.plex_sends_webhooks_only_with_a');
    }

    public function samplePayload(): array
    {
        return [
            'payload' => json_encode([
                'event' => 'media.scrobble',
                'Account' => ['title' => ''],
                'Metadata' => [
                    'type' => 'movie',
                    'title' => 'The Matrix',
                    'year' => 1999,
                    'Guid' => [['id' => 'tmdb://603'], ['id' => 'imdb://tt0133093']],
                ],
            ]),
        ];
    }
}
