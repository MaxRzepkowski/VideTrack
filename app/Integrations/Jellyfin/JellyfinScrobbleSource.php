<?php

namespace App\Integrations\Jellyfin;

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
 * Jellyfin's official Webhook plugin, which posts whatever Handlebars
 * template you give it. We give it one — below, and copyable from the
 * integrations card — so the payload is small, stable, and says exactly
 * what we need.
 *
 * The parser also reads the plugin's "Send All Properties" shape, where the
 * same facts arrive under the plugin's own capitalized names. Somebody will
 * tick that box instead of pasting the template, and a silent endpoint is a
 * worse answer than two spellings in one match arm.
 */
class JellyfinScrobbleSource implements ProvidesSamplePayload, ScrobbleSource
{
    use ReadsPlaybackPayloads;

    public const CODE = 'jellyfin';

    /**
     * What the user pastes into the plugin's Template box.
     *
     * Two things are worth knowing about it. Everything is quoted, because
     * Handlebars renders an absent variable as nothing at all and bare
     * nothing is not JSON. And the series ids are asked for twice: the
     * plugin exposes an episode's *own* provider ids, which are different
     * numbers from its show's, and the show-level ones arrived later and
     * under two different names depending on the build.
     */
    public const TEMPLATE = <<<'HANDLEBARS'
        {
          "notification_type": "{{NotificationType}}",
          "item_type": "{{ItemType}}",
          "name": "{{{Name}}}",
          "series_name": "{{{SeriesName}}}",
          "year": "{{Year}}",
          "season": "{{SeasonNumber}}",
          "episode": "{{EpisodeNumber}}",
          "provider_ids": {
            "tmdb": "{{Provider_tmdb}}",
            "imdb": "{{Provider_imdb}}",
            "tvdb": "{{Provider_tvdb}}"
          },
          "series_provider_ids": {
            "tmdb": "{{#if SeriesProvider_tmdb}}{{SeriesProvider_tmdb}}{{else}}{{SeriesProviderIds.Tmdb}}{{/if}}",
            "imdb": "{{#if SeriesProvider_imdb}}{{SeriesProvider_imdb}}{{else}}{{SeriesProviderIds.Imdb}}{{/if}}",
            "tvdb": "{{#if SeriesProvider_tvdb}}{{SeriesProvider_tvdb}}{{else}}{{SeriesProviderIds.Tvdb}}{{/if}}"
          },
          "played_to_completion": "{{PlayedToCompletion}}",
          "position_ticks": "{{PlaybackPositionTicks}}",
          "runtime_ticks": "{{RunTimeTicks}}",
          "username": "{{NotificationUsername}}",
          "occurred_at": "{{UtcTimestamp}}"
        }
        HANDLEBARS;

    public function code(): string
    {
        return self::CODE;
    }

    public function name(): string
    {
        return 'Jellyfin';
    }

    public function description(): string
    {
        return __('integrations.what_you_finish_on_your_jellyfin');
    }

    public function parse(Request $request): ?ScrobbleEvent
    {
        $payload = $this->readPayload($request);

        $notification = mb_strtolower($this->text($this->field($payload, ['notification_type', 'NotificationType']))
            ?? throw new UnrecognizedPayload('A Jellyfin delivery without a notification type.'));
        // Only the flag about THIS viewing. Other trackers fall back to the
        // item's `UserData.Played`, but that says the title was finished at
        // some point, not now — on a rewatch it is true from the first
        // second, and two minutes of a rerun would log a full play. A
        // client that ends a file without the flag is caught by the
        // percentage instead.
        $completed = $this->truthy($this->field($payload, ['played_to_completion', 'PlayedToCompletion']));

        $kind = match ($notification) {
            'playbackstop' => $completed ? ScrobbleEventKind::Complete : ScrobbleEventKind::Stop,
            'playbackstart' => ScrobbleEventKind::Start,
            'itemadded' => ScrobbleEventKind::Added,
            // PlaybackProgress fires every few seconds and UserDataSaved
            // fires for everything; neither is a viewing.
            default => null,
        };

        $mediaKind = match ($this->lowercase($this->field($payload, ['item_type', 'ItemType']))) {
            'movie' => MediaKind::Movie,
            'episode' => MediaKind::Episode,
            // Seasons, series and music are not something one finishes in
            // a sitting.
            default => null,
        };

        if ($kind === null || $mediaKind === null) {
            return null;
        }

        $series = $this->text($this->field($payload, ['series_name', 'SeriesName']));
        $season = $this->number($this->field($payload, ['season', 'SeasonNumber']));
        $number = $this->number($this->field($payload, ['episode', 'EpisodeNumber']));

        return new ScrobbleEvent(
            kind: $kind,
            mediaKind: $mediaKind,
            externalIds: $this->mergeProviderIds(
                data_get($payload, 'provider_ids'),
                $this->flattenedIds($payload, 'Provider_'),
            ),
            // Four spellings of the same fact, because the show-level ids
            // arrived late and each shape of the payload names them its own
            // way. Without them an episode can only be placed by its own id.
            seriesExternalIds: $this->mergeProviderIds(
                data_get($payload, 'series_provider_ids'),
                data_get($payload, 'SeriesProviderIds'),
                data_get($payload, 'Series.ProviderIds'),
                $this->flattenedIds($payload, 'SeriesProvider_'),
            ),
            title: $mediaKind === MediaKind::Episode
                ? $this->episodeLabel($series, $season, $number)
                : $this->text($this->field($payload, ['name', 'Name'])),
            year: $this->number($this->field($payload, ['year', 'Year'])),
            seasonNumber: $season,
            episodeNumber: $number,
            // Only a stop says how far it got; a start is at the beginning
            // by definition and a completion needs no measuring.
            progressPercent: $kind === ScrobbleEventKind::Stop
                ? $this->percentFromTicks(
                    $this->field($payload, ['position_ticks', 'PlaybackPositionTicks']),
                    $this->field($payload, ['runtime_ticks', 'RunTimeTicks']),
                )
                : null,
            occurredAt: $this->moment($this->field($payload, ['occurred_at', 'UtcTimestamp'])),
            serverUsername: $this->text($this->field($payload, ['username', 'NotificationUsername'])),
        );
    }

    /**
     * "Send All Properties" flattens the provider ids into top-level keys
     * ("Provider_tmdb"), one per catalogue, instead of nesting them.
     *
     * @param  array<array-key, mixed>  $payload
     * @return array<string, mixed>
     */
    private function flattenedIds(array $payload, string $prefix): array
    {
        $ids = [];

        foreach ($payload as $key => $value) {
            if (str_starts_with($key, $prefix)) {
                $ids[substr($key, strlen($prefix))] = $value;
            }
        }

        return $ids;
    }

    public function instructions(): array
    {
        return [
            new SetupStep(
                __('integrations.install_the_webhook_plugin'),
                __('integrations.in_jellyfin_open_dashboard_plugins_catalog'),
            ),
            new SetupStep(
                __('integrations.add_a_generic_destination'),
                __('integrations.dashboard_plugins_webhook_add_generic_destination'),
            ),
            new SetupStep(
                __('integrations.say_that_it_is_json'),
                __('integrations.the_plugin_sends_its_payload_as'),
                'application/json',
            ),
            new SetupStep(
                __('integrations.pick_the_notification_types'),
                __('integrations.tick_playback_stop_leave_playback_progress'),
            ),
            new SetupStep(
                __('integrations.limit_it_to_movies_and_episodes'),
                __('integrations.under_item_types_tick_movies_and'),
            ),
            new SetupStep(
                __('integrations.paste_the_template'),
                __('integrations.turn_send_all_properties_off_and'),
                self::TEMPLATE,
            ),
            new SetupStep(
                __('integrations.one_endpoint_per_person'),
                __('integrations.a_server_everybody_in_the_house_2'),
            ),
        ];
    }

    public function notice(): ?string
    {
        return null;
    }

    public function samplePayload(): array
    {
        return [
            'notification_type' => 'PlaybackStop',
            'item_type' => 'Movie',
            'name' => 'The Matrix',
            'year' => '1999',
            'provider_ids' => ['tmdb' => '603', 'imdb' => 'tt0133093'],
            'played_to_completion' => 'True',
            'position_ticks' => '81600000000',
            'runtime_ticks' => '81600000000',
        ];
    }
}
