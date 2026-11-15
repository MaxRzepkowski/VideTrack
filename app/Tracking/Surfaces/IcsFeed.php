<?php

namespace App\Tracking\Surfaces;

use App\Accounts\UserCalendar;
use App\Models\User;
use App\Tracking\Data\EventEntry;
use App\Tracking\Releases\ReleaseEntry;
use Illuminate\Support\Carbon;
use Spatie\IcalendarGenerator\Components\Calendar;
use Spatie\IcalendarGenerator\Components\Event;

/**
 * The release calendar as an iCalendar document a calendar app subscribes to:
 * the same releases and events as the calendar page, over a wide window.
 */
final readonly class IcsFeed
{
    private const PAST_DAYS = 30;

    private const FUTURE_DAYS = 365;

    /** How often a subscribed calendar app asks for the feed again. */
    private const REFRESH_MINUTES = 12 * 60;

    /** How long a timed release blocks when its runtime is unknown. */
    private const UNKNOWN_RUNTIME_MINUTES = 60;

    public function __construct(private CalendarDays $calendar) {}

    public function render(User $user): string
    {
        $today = UserCalendar::of($user)->today();
        $from = $today->subDays(self::PAST_DAYS)->toDateString();
        $to = $today->addDays(self::FUTURE_DAYS)->toDateString();

        $ical = Calendar::create(config()->string('app.name').': '.$user->username)
            ->productIdentifier(config()->string('app.name').'//Release Calendar')
            ->refreshInterval(self::REFRESH_MINUTES);

        foreach ($this->calendar->range($user, $from, $to) as $day) {
            foreach ($day->entries as $entry) {
                $ical->event($this->event($day->date, $entry));
            }

            foreach ($day->events as $item) {
                $ical->event($this->show($item->event));
            }
        }

        return $ical->get();
    }

    private function event(string $date, ReleaseEntry $entry): Event
    {
        $media = $entry->media;

        $event = Event::create($this->title($entry))
            ->uniqueIdentifier($this->uid($date, $entry));

        // A real broadcast slot becomes a timed event (viewer's app localizes
        // the UTC instant); everything else is an all-day entry on its date.
        if ($media->releaseTime !== null) {
            $start = Carbon::parse($media->releaseTime);
            $runtime = $media->runtime ?? self::UNKNOWN_RUNTIME_MINUTES;

            $event->startsAt($start)->endsAt($start->copy()->addMinutes(max($runtime, 1)));
        } else {
            $event->fullDay()->startsAt(Carbon::parse($date));
        }

        return $event;
    }

    /** A showcase is a timed event from its start to its end, its stream as the link. */
    private function show(EventEntry $show): Event
    {
        $event = Event::create($show->name)
            ->uniqueIdentifier("event-{$show->provider}-{$show->ref}@videtrack")
            ->startsAt(Carbon::parse($show->startsAt))
            ->endsAt(Carbon::parse($show->endsAt));

        return $show->liveStreamUrl === null ? $event : $event->url($show->liveStreamUrl);
    }

    private function title(ReleaseEntry $entry): string
    {
        $media = $entry->media;

        if ($entry->showTitle !== null) {
            $code = sprintf('S%02dE%02d', $media->seasonNumber ?? 0, $media->number ?? 0);

            return "{$entry->showTitle} {$code} – {$media->title}";
        }

        return $media->title;
    }

    /**
     * Stable per release so re-fetches update rather than duplicate; the
     * platform and the release status keep a game's several releases apart,
     * as the calendar page does. (A film's two releases differ by date; on
     * one day they arrive merged.)
     */
    private function uid(string $date, ReleaseEntry $entry): string
    {
        $media = $entry->media;

        // The status only where there is one: every UID already handed out
        // stays what it was, or calendars would show each release twice.
        return implode('-', [
            $media->type,
            $media->ref,
            $media->platform,
            ...($media->releaseStatus === null ? [] : [$media->releaseStatus]),
            $date,
        ]).'@videtrack';
    }
}
