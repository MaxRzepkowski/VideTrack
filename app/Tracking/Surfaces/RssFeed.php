<?php

namespace App\Tracking\Surfaces;

use App\Accounts\UserCalendar;
use App\Models\User;
use App\Support\PublicUrl;
use App\Tracking\Releases\ReleaseEntry;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Traits\Localizable;

/**
 * The release calendar as an RSS 2.0 channel a feed reader follows: the
 * releases of the last two weeks and the next month, newest first. Events
 * stay in the iCal feed, where a time slot is what they are about.
 */
final readonly class RssFeed
{
    use Localizable;

    /** A reader wants announcements, not every episode of every show. */
    public const DEFAULT_SCOPE = FeedScope::Premieres;

    public const PAST_DAYS = 14;

    public const FUTURE_DAYS = 30;

    /** How many items a channel carries, from the newest release back. */
    public const MAX_ITEMS = 100;

    public function __construct(
        private CalendarDays $calendar,
        private FeedItems $items,
    ) {}

    /** The variant's channel in the account's language, whoever's request is being served. */
    public function render(User $user, FeedFilter $filter): string
    {
        return $this->withLocale($user->preferredLocale(), fn (): string => $this->document($user, $filter));
    }

    private function document(User $user, FeedFilter $filter): string
    {
        $calendar = UserCalendar::of($user);
        $today = $calendar->today();
        $days = $filter->days($this->calendar->range(
            $user,
            $today->subDays(self::PAST_DAYS)->toDateString(),
            $today->addDays(self::FUTURE_DAYS)->toDateString(),
        ));

        $items = [];

        foreach ($days as $day) {
            foreach ($day->entries as $entry) {
                $items[] = $this->item($day->date, $entry, $calendar);
            }
        }

        // Newest first, the calendar read backwards: releases out at the same
        // moment (a day's episodes) last to first, so the oldest are what the limit cuts.
        $items = array_reverse($items);
        usort($items, fn (array $a, array $b): int => $b['instant'] <=> $a['instant']);

        // Not in the template: the declaration's closing mark would end the PHP Blade compiles it to.
        return '<?xml version="1.0" encoding="UTF-8"?>'."\n".view('feeds.calendar-rss', [
            'title' => $this->items->name($user),
            'link' => PublicUrl::to(route('calendar', absolute: false)),
            'description' => __('common.upcoming_releases_from_the_titles_you'),
            'language' => App::getLocale(),
            'ttl' => FeedItems::REFRESH_MINUTES,
            'items' => array_slice($items, 0, self::MAX_ITEMS),
        ])->render();
    }

    /**
     * @return array{title: string, link: string, guid: string, instant: CarbonImmutable, published: string, cover: string|null, facts: list<string>}
     */
    private function item(string $date, ReleaseEntry $entry, UserCalendar $calendar): array
    {
        $releaseTime = $entry->media->releaseTime;
        // A broadcast slot is when it came out; otherwise the start of the day it did, for the reader.
        $published = $releaseTime === null ? $calendar->startOf($date) : CarbonImmutable::parse($releaseTime);

        return [
            'title' => __('calendar.feed_item_title', [
                'title' => $this->items->title($entry),
                'date' => CarbonImmutable::parse($date)->settings(['locale' => App::getLocale()])->isoFormat('LL'),
            ]),
            'link' => $this->items->url($entry),
            'guid' => $this->items->uid($date, $entry),
            'instant' => $published,
            'published' => $published->toRfc2822String(),
            'cover' => $this->items->cover($entry),
            'facts' => $this->items->facts($entry),
        ];
    }
}
