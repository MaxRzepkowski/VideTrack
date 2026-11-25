<?php

namespace App\Accounts\Preferences;

use App\Catalog\MatureChoices;
use App\Catalog\MatureContent;
use App\Catalog\ProgressUnit;
use App\Catalog\RelatedReleaseKind;
use App\Enums\ReleaseDateMode;
use App\Enums\UpNextSort;
use App\Support\Payload;
use App\Tracking\Surface;
use App\Tracking\TrackingSource;
use BackedEnum;
use IntlCalendar;
use LogicException;
use stdClass;

/**
 * A user's settings, each with its default in one place. Stored as JSON that
 * holds only what the user chose, so an untouched setting follows its default
 * even when the default changes; a value that no longer means anything (a
 * retired choice, a wrong type) reads as the default.
 */
final readonly class UserPreferences
{
    /** The fastest reading pace a setting takes, per hour in any unit. */
    public const READING_PACE_MAX = 1000;

    /**
     * The kinds of title with sources of their own, kept apart from the
     * scalar settings: type => surface => the rows switched on there. A kind
     * of title absent here, or a surface absent under it, follows the
     * default rows; its own were copied from them once and stay its own.
     */
    public const TYPE_SOURCES = 'type_sources';

    /** How far each provider's listings go, kept apart from the scalar settings: provider code => MatureContent. */
    public const MATURE_CONTENT = 'mature_content';

    /**
     * Every setting and its default: a flag, a choice (its enum's default
     * case), a whole number or a text that stays null until the user picks one.
     *
     * @var array<string, bool|int|BackedEnum|null>
     */
    private const DEFAULTS = [
        'spoiler_protection' => true,
        // null = automatic: the browser's timezone.
        'timezone' => null,
        // What "automatic" resolved to in the browser, for work done in a queue.
        'detected_timezone' => null,
        'time_format' => TimeFormat::Auto,
        'week_start' => WeekStart::Auto,
        // null = automatic: the country of the UI language.
        'region' => null,
        'up_next_target' => UpNextTarget::Season,
        // A finished show's card lingers until the user leaves the page.
        'up_next_keep_finished' => true,
        'up_next_sort' => UpNextSort::Activity,

        // The tracking matrix: rows × surfaces, plus each surface's own
        // options. Notifications are quieter than the calendar: the paused
        // and dropped rows stay on in passive views but do not ping, and a
        // leaner countdown list leaves the dropped row out. A bell the user
        // rang or a Backlog spot still speaks for such a title, by its own row.
        'calendar_active' => true,
        'calendar_dropped' => true,
        'calendar_on_hold' => true,
        'calendar_watchlist' => true,
        'calendar_followed' => true,
        'calendar_creators' => true,
        'calendar_game_platforms' => true,
        'calendar_release_mode' => ReleaseDateMode::Both,
        'calendar_episode_image' => EpisodeImage::Backdrop,
        'calendar_events' => true,
        'calendar_game_addons' => true,
        'calendar_game_remakes' => true,
        'calendar_game_remasters' => true,
        'countdown_active' => true,
        'countdown_dropped' => false,
        'countdown_on_hold' => true,
        'countdown_watchlist' => true,
        'countdown_followed' => true,
        'countdown_creators' => true,
        'countdown_game_platforms' => false,
        'countdown_release_mode' => ReleaseDateMode::Both,
        'countdown_episode_image' => EpisodeImage::Backdrop,
        'countdown_events' => true,
        'countdown_game_addons' => true,
        'countdown_game_remakes' => true,
        'countdown_game_remasters' => true,
        'notifications_active' => true,
        'notifications_dropped' => false,
        'notifications_on_hold' => false,
        'notifications_watchlist' => true,
        'notifications_followed' => true,
        'notifications_creators' => true,
        'notifications_release_mode' => ReleaseDateMode::Both,
        'notifications_game_addons' => true,
        'notifications_game_remakes' => true,
        'notifications_game_remasters' => true,

        'notify_episodes' => true,
        'notify_premieres' => true,
        'notify_date_changes' => true,
        'notify_show_status' => true,
        'notify_person_titles' => true,
        'notify_company_titles' => true,
        'notify_collection_items' => true,
        'notify_related_announced' => false,
        'person_follow_scope' => PersonFollowScope::KnownFor,
        'company_follow_scope' => CompanyFollowScope::Both,

        // How fast the user reads, per hour in each unit a type is read in:
        // what turns pages, chapters and issues into an estimate of time.
        'reading_pace_pages' => 50,
        'reading_pace_chapters' => 12,
        'reading_pace_issues' => 6,
    ];

    /** The kinds of title with sources of their own, read once: the matrix asks for every tracked title. */
    private TypeSources $typeSources;

    /**
     * @param  array<string, mixed>  $stored  what the user chose, keyed as above
     */
    private function __construct(private array $stored)
    {
        $this->typeSources = TypeSources::fromStored($stored[self::TYPE_SOURCES] ?? null);
    }

    /**
     * @param  array<string, mixed>  $stored
     */
    public static function fromStored(array $stored): self
    {
        return new self($stored);
    }

    /**
     * These preferences with some settings chosen anew; the rest stay as they
     * were, so a page that sends only its own settings never resets another's.
     *
     * @param  array<string, mixed>  $changes  validated, keyed as stored
     */
    public function merge(array $changes): self
    {
        return new self([...$this->stored, ...$changes]);
    }

    /**
     * @return array<string, mixed> what the user chose, nothing filled in
     */
    public function toStored(): array
    {
        return $this->stored;
    }

    /**
     * Every setting, the user's choice or its default — what the front reads,
     * so it never keeps defaults of its own.
     *
     * @return array<string, bool|int|string|array<string, array<string, list<string>>>|array<string, string>|stdClass|null>
     */
    public function resolved(): array
    {
        $resolved = [];

        foreach (array_keys(self::DEFAULTS) as $key) {
            $resolved[$key] = $this->value($key);
        }

        // As the server reads it, so the page's picker shows the country
        // the calendar actually uses.
        $resolved['region'] = $this->region();

        $resolved[self::TYPE_SOURCES] = $this->typeSources->toWire();

        // What the user chose, provider by provider; one left out keeps to
        // MatureChoices' own level, so no default is copied here.
        $levels = array_map(fn (MatureContent $level): string => $level->value, $this->matureLevels());
        $resolved[self::MATURE_CONTENT] = $levels === [] ? new stdClass : $levels;

        return $resolved;
    }

    public function spoilerProtection(): bool
    {
        return $this->flag('spoiler_protection');
    }

    /**
     * The timezone this user's clock is read in: their explicit choice, else
     * the zone their browser last reported, else the instance's. "Automatic"
     * stores no choice — the browser resolves it — so work done in a queue
     * (the daily digest above all) would otherwise land on the instance's
     * timezone and fire at the wrong local hour.
     */
    public function effectiveTimezone(): string
    {
        return $this->text('timezone') ?? $this->text('detected_timezone') ?? config()->string('app.timezone');
    }

    /** The country the user chose, or null for the one of their UI language. */
    public function region(): ?string
    {
        $region = $this->text('region');

        return $region !== null && strlen($region) === 2 ? strtoupper($region) : null;
    }

    /** How far the user lets each provider's listings go. */
    public function matureContent(): MatureChoices
    {
        return new MatureChoices($this->matureLevels());
    }

    /** One order for every in-progress section of the dashboard. */
    public function upNextSort(): UpNextSort
    {
        return $this->choice('up_next_sort', UpNextSort::class);
    }

    /**
     * The rows of the tracking matrix the user lets feed a surface: for a
     * kind of title with sources of its own there, its own; else the default.
     *
     * @return list<TrackingSource>
     */
    public function matrix(Surface $surface, ?string $type = null): array
    {
        $own = $type === null ? null : $this->typeSources->rows($type, $surface);

        return array_values(array_filter(
            TrackingSource::cases(),
            fn (TrackingSource $row): bool => $own !== null
                ? in_array($row, $own, true)
                : $this->flag(PreferenceKeys::matrix($surface, $row)),
        ));
    }

    /**
     * Which of a film's release moments a surface shows — per surface, like
     * the matrix, because a leaner countdown list is a different want from a
     * complete calendar.
     */
    public function releaseMode(Surface $surface): ReleaseDateMode
    {
        return $this->choice(PreferenceKeys::releaseMode($surface), ReleaseDateMode::class);
    }

    /**
     * Each platform's premiere of a game as its own entry, rather than the
     * game's first date alone. A notification is about the title, so it has
     * no such option.
     */
    public function perPlatform(Surface $surface): bool
    {
        return $surface->isView() && $this->flag(PreferenceKeys::perPlatform($surface));
    }

    /**
     * Whether a view shows the events (showcases, presentations) of the
     * providers here. No event pings, so the notifications never do.
     */
    public function showsEvents(Surface $surface): bool
    {
        return $surface->isView() && $this->flag(PreferenceKeys::events($surface));
    }

    /**
     * Whether a surface tells of a tracked game's releases of their own of
     * one kind (its DLC, its remakes, its remasters) beside the game.
     */
    public function showsRelated(Surface $surface, RelatedReleaseKind $kind): bool
    {
        return $this->flag(PreferenceKeys::related($surface, $kind));
    }

    public function notifies(NotificationEvent $event): bool
    {
        return $this->flag(PreferenceKeys::notifies($event));
    }

    public function personFollowScope(): PersonFollowScope
    {
        return $this->choice('person_follow_scope', PersonFollowScope::class);
    }

    public function companyFollowScope(): CompanyFollowScope
    {
        return $this->choice('company_follow_scope', CompanyFollowScope::class);
    }

    /**
     * Which weekday opens the user's week (ISO: 1 is Monday, 7 Sunday): their
     * choice, else their language's own week, as the browser's calendar
     * reads it — Monday in Poland, Sunday in the US.
     */
    public function firstWeekday(string $locale): int
    {
        return match ($this->choice('week_start', WeekStart::class)) {
            WeekStart::Monday => 1,
            WeekStart::Sunday => 7,
            // ICU counts from Sunday (1) to Saturday (7).
            WeekStart::Auto => (IntlCalendar::createInstance(null, $locale)->getFirstDayOfWeek() + 5) % 7 + 1,
        };
    }

    /**
     * How many of a unit the user reads in an hour; null for a unit that is
     * time already.
     */
    public function readingPace(ProgressUnit $unit): ?int
    {
        if (! $unit->isRead()) {
            return null;
        }

        $value = $this->value('reading_pace_'.$unit->value);

        return is_int($value) ? $value : null;
    }

    /**
     * The adult content levels the user chose, by provider code; a level that
     * no longer means anything reads as no choice.
     *
     * @return array<string, MatureContent>
     */
    private function matureLevels(): array
    {
        $levels = [];
        $stored = Payload::named($this->stored[self::MATURE_CONTENT] ?? null);

        foreach (array_keys($stored) as $provider) {
            $chosen = Payload::enum($stored, $provider, MatureContent::class);

            if ($chosen !== null) {
                $levels[$provider] = $chosen;
            }
        }

        return $levels;
    }

    /** The user's choice if it still means something, else the default. */
    private function value(string $key): bool|int|string|null
    {
        if (! array_key_exists($key, self::DEFAULTS)) {
            throw new LogicException("No preference named {$key}.");
        }

        $default = self::DEFAULTS[$key];
        $stored = $this->stored[$key] ?? null;

        if (is_bool($default)) {
            return is_bool($stored) ? $stored : $default;
        }

        if (is_int($default)) {
            return is_int($stored) && $stored > 0 ? $stored : $default;
        }

        if ($default instanceof BackedEnum) {
            $chosen = is_string($stored) ? $default::tryFrom($stored) : null;

            return (string) ($chosen ?? $default)->value;
        }

        return is_string($stored) && $stored !== '' ? $stored : null;
    }

    private function flag(string $key): bool
    {
        return $this->value($key) === true;
    }

    private function text(string $key): ?string
    {
        $value = $this->value($key);

        return is_string($value) ? $value : null;
    }

    /**
     * @template T of BackedEnum
     *
     * @param  class-string<T>  $enum
     * @return T
     */
    private function choice(string $key, string $enum): BackedEnum
    {
        return $enum::from((string) $this->value($key));
    }
}
