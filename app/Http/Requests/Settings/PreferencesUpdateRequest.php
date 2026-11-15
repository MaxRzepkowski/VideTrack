<?php

namespace App\Http\Requests\Settings;

use App\Accounts\Preferences\CompanyFollowScope;
use App\Accounts\Preferences\DiscordMode;
use App\Accounts\Preferences\EpisodeImage;
use App\Accounts\Preferences\PersonFollowScope;
use App\Accounts\Preferences\TimeFormat;
use App\Accounts\Preferences\UpNextTarget;
use App\Accounts\Preferences\WeekStart;
use App\Enums\ReleaseDateMode;
use App\Enums\UpNextSort;
use App\Support\Payload;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Arr;
use Illuminate\Validation\Rule;

class PreferencesUpdateRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            // Two settings pages (Preferences, Tracking) share this endpoint,
            // each sending only its own keys — everything is `sometimes`.
            'spoiler_protection' => ['sometimes', 'boolean'],
            // Empty = automatic (the browser's timezone).
            'timezone' => ['sometimes', 'nullable', 'string', 'timezone:all'],
            // auto = follow the UI locale's hour cycle.
            'time_format' => ['sometimes', 'string', Rule::enum(TimeFormat::class)],
            // auto = follow the UI locale's own week (Monday in Poland, Sunday
            // in the US); the calendar's month grid starts its weeks on it.
            'week_start' => ['sometimes', 'string', Rule::enum(WeekStart::class)],
            // Tracking matrix: sources × {calendar, countdown, notifications}.
            'calendar_active' => ['sometimes', 'boolean'],
            'calendar_dropped' => ['sometimes', 'boolean'],
            'calendar_on_hold' => ['sometimes', 'boolean'],
            'calendar_watchlist' => ['sometimes', 'boolean'],
            'calendar_followed' => ['sometimes', 'boolean'],
            'calendar_creators' => ['sometimes', 'boolean'],
            'calendar_game_platforms' => ['sometimes', 'boolean'],
            // Which image an episode shows in the calendar.
            'calendar_episode_image' => ['sometimes', 'string', Rule::enum(EpisodeImage::class)],
            'countdown_active' => ['sometimes', 'boolean'],
            'countdown_dropped' => ['sometimes', 'boolean'],
            'countdown_on_hold' => ['sometimes', 'boolean'],
            'countdown_watchlist' => ['sometimes', 'boolean'],
            'countdown_followed' => ['sometimes', 'boolean'],
            'countdown_creators' => ['sometimes', 'boolean'],
            'countdown_game_platforms' => ['sometimes', 'boolean'],
            'notifications_active' => ['sometimes', 'boolean'],
            'notifications_dropped' => ['sometimes', 'boolean'],
            'notifications_on_hold' => ['sometimes', 'boolean'],
            'notifications_watchlist' => ['sometimes', 'boolean'],
            'notifications_followed' => ['sometimes', 'boolean'],
            'notifications_creators' => ['sometimes', 'boolean'],
            // Which of a film's release moments each surface shows: its
            // premiere, the day it leaves cinemas, or both.
            'calendar_release_mode' => ['sometimes', 'string', Rule::enum(ReleaseDateMode::class)],
            'countdown_release_mode' => ['sometimes', 'string', Rule::enum(ReleaseDateMode::class)],
            'notifications_release_mode' => ['sometimes', 'string', Rule::enum(ReleaseDateMode::class)],
            // Which event kinds create notifications.
            'notify_episodes' => ['sometimes', 'boolean'],
            'notify_premieres' => ['sometimes', 'boolean'],
            'notify_date_changes' => ['sometimes', 'boolean'],
            'notify_show_status' => ['sometimes', 'boolean'],
            'notify_person_titles' => ['sometimes', 'boolean'],
            'notify_company_titles' => ['sometimes', 'boolean'],
            'notify_collection_items' => ['sometimes', 'boolean'],
            // Which departments of a followed person feed auto-follows.
            'person_follow_scope' => ['sometimes', 'string', Rule::enum(PersonFollowScope::class)],
            // Which roles of a followed company feed auto-follows.
            'company_follow_scope' => ['sometimes', 'string', Rule::enum(CompanyFollowScope::class)],
            // Empty = Discord delivery off.
            'discord_webhook_url' => [
                'sometimes', 'nullable', 'string', 'max:400', 'url',
                'starts_with:https://discord.com/api/webhooks/,https://discordapp.com/api/webhooks/',
            ],
            'discord_mode' => ['sometimes', 'string', Rule::enum(DiscordMode::class)],
            // Which image an episode shows on the countdowns page.
            'countdown_episode_image' => ['sometimes', 'string', Rule::enum(EpisodeImage::class)],
            // An ISO 3166-1 country; empty = automatic (derived from the UI locale).
            'region' => ['sometimes', 'nullable', 'string', 'size:2', 'alpha'],
            // Where an "Up Next" card navigates when clicked.
            'up_next_target' => ['sometimes', 'string', Rule::enum(UpNextTarget::class)],
            // Whether a finished show's card lingers until you leave the page.
            'up_next_keep_finished' => ['sometimes', 'boolean'],
            // How the dashboard's in-progress sections are ordered.
            'up_next_sort' => ['sometimes', 'string', Rule::enum(UpNextSort::class)],
        ];
    }

    /**
     * The settings the page sent, as the preferences store them: flags as
     * booleans, a country in capitals. The webhook is a credential, not a
     * preference ({@see webhookUrl()}).
     *
     * @return array<string, mixed>
     */
    public function preferences(): array
    {
        $rules = $this->rules();
        $preferences = Payload::named(Arr::except($this->validated(), ['discord_webhook_url']));

        foreach (array_keys($preferences) as $key) {
            if (is_array($rules[$key] ?? null) && in_array('boolean', $rules[$key], true)) {
                $preferences[$key] = $this->boolean($key);
            }
        }

        if (is_string($preferences['region'] ?? null)) {
            $preferences['region'] = strtoupper($preferences['region']);
        }

        return $preferences;
    }

    /** The webhook to deliver to; null turns Discord off. */
    public function webhookUrl(): ?string
    {
        $url = $this->validated('discord_webhook_url');

        return is_string($url) ? $url : null;
    }
}
