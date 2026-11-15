<?php

namespace App\Integrations\Emby;

use App\Catalog\MediaKind;
use App\Integrations\Jellyfin\JellyfinScrobbleSource;
use App\Integrations\Scrobble\Concerns\ReadsPlaybackPayloads;
use App\Integrations\Scrobble\Contracts\ProvidesSamplePayload;
use App\Integrations\Scrobble\Contracts\ScrobbleSource;
use App\Integrations\Scrobble\Data\ScrobbleEvent;
use App\Integrations\Scrobble\Data\SetupStep;
use App\Integrations\Scrobble\ScrobbleEventKind;
use App\Integrations\Scrobble\UnrecognizedPayload;
use Illuminate\Http\Request;

/**
 * Emby's built-in webhooks, which post their own JSON — no template to
 * paste, and no template to get wrong. The shape is Emby's item model:
 * an `Item` with the library's fields, a `PlaybackInfo` with the position,
 * and a `User` naming who was watching.
 *
 * Emby names an episode's provider ids after the episode, not after its
 * show, and ships no show-level ids of its own. An episode therefore places
 * itself through its own imdb or tvdb id; libraries scraped without either
 * one show up in the log as unmatched, which is the honest answer.
 */
class EmbyScrobbleSource implements ProvidesSamplePayload, ScrobbleSource
{
    use ReadsPlaybackPayloads;

    public const CODE = 'emby';

    public function code(): string
    {
        return self::CODE;
    }

    public function name(): string
    {
        return 'Emby';
    }

    public function description(): string
    {
        return __('integrations.what_you_finish_on_your_emby');
    }

    public function parse(Request $request): ?ScrobbleEvent
    {
        $payload = $this->readPayload($request);

        // About this viewing only — `Item.UserData.Played` would also be
        // true through every second of a rewatch. {@see JellyfinScrobbleSource}
        $completed = $this->truthy($this->field($payload, ['PlaybackInfo.PlayedToCompletion', 'PlayedToCompletion']));

        $event = $this->text($this->field($payload, ['Event'])) ?? throw new UnrecognizedPayload('An Emby delivery without an event.');

        $kind = match (mb_strtolower($event)) {
            'playback.stop' => $completed ? ScrobbleEventKind::Complete : ScrobbleEventKind::Stop,
            'playback.start', 'playback.unpause' => ScrobbleEventKind::Start,
            'playback.pause' => ScrobbleEventKind::Pause,
            // Emby's own star rating stays in Emby; the row exists so the
            // log shows the server reaching us.
            'item.rate' => ScrobbleEventKind::Rate,
            // Everything else — the server's test button, marking things
            // played by hand, library scans — is not a viewing.
            default => null,
        };

        $mediaKind = match ($this->lowercase($this->field($payload, ['Item.Type']))) {
            'movie' => MediaKind::Movie,
            'episode' => MediaKind::Episode,
            default => null,
        };

        if ($kind === null || $mediaKind === null) {
            return null;
        }

        $series = $this->text($this->field($payload, ['Item.SeriesName']));
        $season = $this->number($this->field($payload, ['Item.ParentIndexNumber']));
        $number = $this->number($this->field($payload, ['Item.IndexNumber']));

        return new ScrobbleEvent(
            kind: $kind,
            mediaKind: $mediaKind,
            externalIds: $this->mergeProviderIds(data_get($payload, 'Item.ProviderIds')),
            // Read in case a build carries them; Emby has not always.
            seriesExternalIds: $this->mergeProviderIds(
                data_get($payload, 'Item.SeriesProviderIds'),
                data_get($payload, 'Item.Series.ProviderIds'),
            ),
            title: $mediaKind === MediaKind::Episode
                ? $this->episodeLabel($series, $season, $number)
                : $this->text($this->field($payload, ['Item.Name'])),
            year: $this->number($this->field($payload, ['Item.ProductionYear'])),
            seasonNumber: $season,
            episodeNumber: $number,
            progressPercent: $kind === ScrobbleEventKind::Stop
                ? $this->percentFromTicks(
                    $this->field($payload, ['PlaybackInfo.PositionTicks']),
                    $this->field($payload, ['Item.RunTimeTicks', 'PlaybackInfo.RunTimeTicks']),
                )
                : null,
            occurredAt: $this->moment($this->field($payload, ['Date'])),
            serverUsername: $this->text($this->field($payload, ['User.Name'])),
        );
    }

    public function instructions(): array
    {
        return [
            new SetupStep(
                __('integrations.open_the_webhook_settings'),
                __('integrations.in_emby_go_to_settings_notifications'),
            ),
            new SetupStep(
                __('integrations.point_it_at_this_address'),
                __('integrations.paste_the_address_above_as_the'),
                'application/json',
            ),
            new SetupStep(
                __('integrations.pick_the_playback_events'),
                __('integrations.tick_playback_events_stopping_a_film'),
            ),
            new SetupStep(
                __('integrations.one_endpoint_per_person'),
                __('integrations.a_server_everybody_in_the_house'),
            ),
        ];
    }

    public function notice(): ?string
    {
        return __('integrations.emby_sends_webhooks_only_with_emby');
    }

    public function samplePayload(): array
    {
        return [
            'Event' => 'playback.stop',
            'Item' => [
                'Name' => 'The Matrix',
                'Type' => 'Movie',
                'ProductionYear' => 1999,
                'ProviderIds' => ['Tmdb' => '603', 'Imdb' => 'tt0133093'],
                'RunTimeTicks' => 81600000000,
            ],
            'PlaybackInfo' => [
                'PlayedToCompletion' => true,
                'PositionTicks' => 81600000000,
            ],
        ];
    }
}
