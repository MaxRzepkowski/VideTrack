<?php

namespace App\Integrations\Tautulli;

use App\Catalog\MediaKind;
use App\Integrations\Scrobble\Concerns\ReadsPlaybackPayloads;
use App\Integrations\Scrobble\Contracts\ProvidesSamplePayload;
use App\Integrations\Scrobble\Contracts\ScrobbleSource;
use App\Integrations\Scrobble\Data\ScrobbleEvent;
use App\Integrations\Scrobble\Data\SetupStep;
use App\Integrations\Scrobble\ScrobbleEventKind;
use App\Integrations\Scrobble\UnrecognizedPayload;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;

/**
 * Tautulli's Webhook agent — the free way to hear from Plex, whose own
 * webhooks need a Plex Pass. Tautulli watches the server's sessions and posts
 * whatever JSON template it is given; we give it the one below.
 *
 * Two things about how Tautulli fills that template shape the parser. Every
 * substitution arrives as a string, and a value Tautulli does not have is an
 * empty string rather than a missing key. And the provider ids it offers on
 * an episode belong to the *show* (it reads them from the grandparent), so
 * they place the episode through its season and number, never on their own.
 */
class TautulliScrobbleSource implements ProvidesSamplePayload, ScrobbleSource
{
    use ReadsPlaybackPayloads;

    public const CODE = 'tautulli';

    /**
     * What the user pastes as JSON Data under each trigger.
     *
     * Tautulli parses this as JSON before substituting anything, so every
     * parameter sits inside a string. `{action}` names the trigger, which is
     * what lets one template serve all of them.
     */
    public const TEMPLATE = <<<'JSON'
        {
          "action": "{action}",
          "media_type": "{media_type}",
          "title": "{title}",
          "show_name": "{show_name}",
          "year": "{year}",
          "season": "{season_num}",
          "episode": "{episode_num}",
          "ids": {
            "tmdb": "{themoviedb_id}",
            "imdb": "{imdb_id}",
            "tvdb": "{thetvdb_id}"
          },
          "progress": "{progress_percent}",
          "username": "{username}",
          "occurred_at": "{unixtime}"
        }
        JSON;

    public function code(): string
    {
        return self::CODE;
    }

    public function name(): string
    {
        return 'Tautulli';
    }

    public function description(): string
    {
        return __('integrations.what_you_finish_on_plex_tautulli');
    }

    public function parse(Request $request): ?ScrobbleEvent
    {
        $payload = $this->readPayload($request);

        $action = $this->text($payload['action'] ?? null) ?? throw new UnrecognizedPayload('A Tautulli delivery without an action.');

        // The Test button posts the template with its placeholders unfilled:
        // what the user wrote is what there is to look at.
        if (str_starts_with($action, '{')) {
            throw new UnrecognizedPayload("Tautulli's template, its placeholders unfilled.");
        }

        $kind = match (mb_strtolower($action)) {
            // Tautulli has already decided this one was watched, by its own
            // threshold.
            'watched' => ScrobbleEventKind::Complete,
            'stop' => ScrobbleEventKind::Stop,
            'pause' => ScrobbleEventKind::Pause,
            'play', 'resume' => ScrobbleEventKind::Start,
            'created' => ScrobbleEventKind::Added,
            // Buffering, errors, intros and the rest are not viewings.
            default => null,
        };

        $mediaKind = match (mb_strtolower((string) $this->text($payload['media_type'] ?? null))) {
            'movie' => MediaKind::Movie,
            'episode' => MediaKind::Episode,
            // Shows and seasons (from Recently Added), music and clips.
            default => null,
        };

        if ($kind === null || $mediaKind === null) {
            return null;
        }

        $episode = $mediaKind === MediaKind::Episode;
        $ids = $this->providerIds($payload['ids'] ?? null);
        $season = $this->number($payload['season'] ?? null);
        $number = $this->number($payload['episode'] ?? null);

        return new ScrobbleEvent(
            kind: $kind,
            mediaKind: $mediaKind,
            externalIds: $episode ? [] : $ids,
            seriesExternalIds: $episode ? $ids : [],
            title: $episode
                ? $this->episodeLabel($this->text($payload['show_name'] ?? null), $season, $number)
                : $this->text($payload['title'] ?? null),
            year: $this->number($payload['year'] ?? null),
            seasonNumber: $season,
            episodeNumber: $number,
            // How far a stop or a pause got; a completion needs no measuring.
            progressPercent: in_array($kind, [ScrobbleEventKind::Stop, ScrobbleEventKind::Pause], true)
                ? $this->percent($payload['progress'] ?? null)
                : null,
            occurredAt: $this->unixMoment($payload['occurred_at'] ?? null),
            serverUsername: $this->text($payload['username'] ?? null),
        );
    }

    private function percent(mixed $value): ?float
    {
        return is_numeric($value) ? min(100.0, max(0.0, (float) $value)) : null;
    }

    /** `{unixtime}` is epoch seconds in a string, which a date parser misreads. */
    private function unixMoment(mixed $value): ?CarbonImmutable
    {
        $value = $this->text($value);

        if ($value !== null && ctype_digit($value)) {
            return CarbonImmutable::createFromTimestamp((int) $value);
        }

        return $this->moment($value);
    }

    public function instructions(): array
    {
        return [
            new SetupStep(
                __('integrations.add_a_webhook_agent'),
                __('integrations.in_tautulli_open_settings_notification_agents'),
            ),
            new SetupStep(
                __('integrations.paste_the_address'),
                __('integrations.on_the_configuration_tab_paste_the'),
            ),
            new SetupStep(
                __('integrations.pick_the_trigger'),
                __('integrations.on_the_triggers_tab_tick_watched'),
            ),
            new SetupStep(
                __('integrations.paste_the_template'),
                __('integrations.on_the_data_tab_open_watched'),
                self::TEMPLATE,
            ),
            new SetupStep(
                __('integrations.one_endpoint_per_person'),
                __('integrations.tautulli_reports_everybody_who_plays_on'),
            ),
            new SetupStep(
                __('integrations.when_it_counts_as_watched'),
                __('integrations.tautulli_decides_by_its_own_threshold'),
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
            'action' => 'watched',
            'media_type' => 'movie',
            'title' => 'The Matrix',
            'show_name' => '',
            'year' => '1999',
            'season' => '',
            'episode' => '',
            'ids' => ['tmdb' => '603', 'imdb' => 'tt0133093', 'tvdb' => ''],
            'progress' => '100',
            'username' => '',
            'occurred_at' => '',
        ];
    }
}
