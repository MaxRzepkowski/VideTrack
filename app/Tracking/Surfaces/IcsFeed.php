<?php

namespace App\Tracking\Surfaces;

use App\Accounts\UserCalendar;
use App\Models\User;
use App\Tracking\Data\EventEntry;
use App\Tracking\Releases\ReleaseEntry;
use Illuminate\Support\Carbon;
use Illuminate\Support\Traits\Localizable;
use Spatie\IcalendarGenerator\Components\Calendar;
use Spatie\IcalendarGenerator\Components\Event;

/**
 * The release calendar as an iCalendar document a calendar app subscribes to:
 * the same releases and events as the calendar page, over a wide window,
 * narrowed to the variant the address asks for.
 */
final readonly class IcsFeed
{
    use Localizable;

    /** A calendar shows everything it is given; the address narrows it. */
    public const DEFAULT_SCOPE = FeedScope::All;

    private const PAST_DAYS = 30;

    private const FUTURE_DAYS = 365;

    /** How long a timed release blocks when its runtime is unknown. */
    private const UNKNOWN_RUNTIME_MINUTES = 60;

    public function __construct(
        private CalendarDays $calendar,
        private FeedItems $items,
    ) {}

    /** The variant's calendar in the account's language, whoever's request is being served. */
    public function render(User $user, FeedFilter $filter): string
    {
        return $this->withLocale($user->preferredLocale(), fn (): string => $this->document($user, $filter));
    }

    private function document(User $user, FeedFilter $filter): string
    {
        $today = UserCalendar::of($user)->today();
        $from = $today->subDays(self::PAST_DAYS)->toDateString();
        $to = $today->addDays(self::FUTURE_DAYS)->toDateString();

        $ical = Calendar::create($this->items->name($user))
            ->productIdentifier(config()->string('app.name').'//Release Calendar')
            ->refreshInterval(FeedItems::REFRESH_MINUTES);

        foreach ($filter->days($this->calendar->range($user, $from, $to)) as $day) {
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

        $event = Event::create($this->items->title($entry))
            ->uniqueIdentifier($this->items->uid($date, $entry))
            ->url($this->items->url($entry));

        $facts = $this->items->facts($entry);

        if ($facts !== []) {
            $event->description(implode("\n", $facts));
        }

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
}
