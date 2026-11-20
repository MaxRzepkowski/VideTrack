<?php

namespace App\Http\Requests\Settings;

use App\Accounts\Preferences\CompanyFollowScope;
use App\Accounts\Preferences\EpisodeImage;
use App\Accounts\Preferences\PersonFollowScope;
use App\Accounts\Preferences\TimeFormat;
use App\Accounts\Preferences\UpNextTarget;
use App\Accounts\Preferences\UserPreferences;
use App\Accounts\Preferences\WeekStart;
use App\Catalog\MediaTypeRegistry;
use App\Enums\UpNextSort;
use App\Support\Payload;
use App\Tracking\Settings\TypePanels;
use App\Tracking\Surface;
use App\Tracking\TrackingSource;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class PreferencesUpdateRequest extends FormRequest
{
    /** @var array<string, mixed>|null */
    private ?array $rules = null;

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        // Read again by preferences(): the type panels are built once.
        return $this->rules ??= [
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
            // Which image an episode shows in the calendar.
            'calendar_episode_image' => ['sometimes', 'string', Rule::enum(EpisodeImage::class)],
            'countdown_active' => ['sometimes', 'boolean'],
            'countdown_dropped' => ['sometimes', 'boolean'],
            'countdown_on_hold' => ['sometimes', 'boolean'],
            'countdown_watchlist' => ['sometimes', 'boolean'],
            'countdown_followed' => ['sometimes', 'boolean'],
            'countdown_creators' => ['sometimes', 'boolean'],
            'notifications_active' => ['sometimes', 'boolean'],
            'notifications_dropped' => ['sometimes', 'boolean'],
            'notifications_on_hold' => ['sometimes', 'boolean'],
            'notifications_watchlist' => ['sometimes', 'boolean'],
            'notifications_followed' => ['sometimes', 'boolean'],
            'notifications_creators' => ['sometimes', 'boolean'],
            // Which event kinds create notifications, besides those of a kind of title.
            'notify_premieres' => ['sometimes', 'boolean'],
            'notify_date_changes' => ['sometimes', 'boolean'],
            'notify_person_titles' => ['sometimes', 'boolean'],
            'notify_company_titles' => ['sometimes', 'boolean'],
            'notify_collection_items' => ['sometimes', 'boolean'],
            // Which departments of a followed person feed auto-follows.
            'person_follow_scope' => ['sometimes', 'string', Rule::enum(PersonFollowScope::class)],
            // Which roles of a followed company feed auto-follows.
            'company_follow_scope' => ['sometimes', 'string', Rule::enum(CompanyFollowScope::class)],
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
            // How many of each unit the user reads in an hour, for the estimated reading time.
            'reading_pace_pages' => ['sometimes', 'integer', 'min:1', 'max:'.UserPreferences::READING_PACE_MAX],
            'reading_pace_chapters' => ['sometimes', 'integer', 'min:1', 'max:'.UserPreferences::READING_PACE_MAX],
            'reading_pace_issues' => ['sometimes', 'integer', 'min:1', 'max:'.UserPreferences::READING_PACE_MAX],
            // What only some kinds of title have (a film's two dates, a game's
            // platforms, a series' episodes), as the code reading it declares.
            ...array_map(
                fn (?array $choices): array => $choices === null ? ['sometimes', 'boolean'] : ['sometimes', 'string', Rule::in($choices)],
                app(TypePanels::class)->preferences(),
            ),
            // The kinds of title with sources of their own, sent whole: a kind
            // left out follows the defaults again. The views share one switch,
            // so the calendar and the countdowns come together.
            UserPreferences::TYPE_SOURCES => ['sometimes', 'array:'.implode(',', app(MediaTypeRegistry::class)->rootCodes())],
            UserPreferences::TYPE_SOURCES.'.*' => ['array:'.implode(',', array_column(Surface::cases(), 'value'))],
            ...$this->surfaceRules(),
            // A row twice in one list reads once; `distinct` would compare every list with every other.
            UserPreferences::TYPE_SOURCES.'.*.*.*' => [Rule::enum(TrackingSource::class)],
        ];
    }

    /**
     * Each surface's rows, a list no longer than the rows there are; the
     * views come together, as their one switch sends them.
     *
     * @return array<string, list<string>>
     */
    private function surfaceRules(): array
    {
        $rules = [];
        $views = Surface::views();

        foreach (Surface::cases() as $surface) {
            $partners = array_map(
                fn (Surface $view): string => 'present_with:'.UserPreferences::TYPE_SOURCES.'.*.'.$view->value,
                $surface->isView() ? array_values(array_filter($views, fn (Surface $view): bool => $view !== $surface)) : [],
            );

            $rules[UserPreferences::TYPE_SOURCES.'.*.'.$surface->value] = [...$partners, 'list', 'max:'.count(TrackingSource::cases())];
        }

        return $rules;
    }

    /**
     * The settings the page sent, as the preferences store them: flags as
     * booleans, numbers as integers, a country in capitals.
     *
     * @return array<string, mixed>
     */
    public function preferences(): array
    {
        $rules = $this->rules();
        $preferences = Payload::named($this->validated());

        foreach (array_keys($preferences) as $key) {
            if (is_array($rules[$key] ?? null) && in_array('boolean', $rules[$key], true)) {
                $preferences[$key] = $this->boolean($key);
            }

            if (is_array($rules[$key] ?? null) && in_array('integer', $rules[$key], true)) {
                $preferences[$key] = $this->integer($key);
            }
        }

        if (is_string($preferences['region'] ?? null)) {
            $preferences['region'] = strtoupper($preferences['region']);
        }

        return $preferences;
    }
}
