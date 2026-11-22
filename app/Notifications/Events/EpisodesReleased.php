<?php

namespace App\Notifications\Events;

use App\Catalog\EpisodeBadge;
use App\Catalog\EpisodeCode;
use App\Notifications\Data\EventLine;
use App\Notifications\MediaEventPayload;
use App\Notifications\MediaEventType;
use App\Support\Payload;
use App\Tracking\Releases\ReleaseEntry;
use InvalidArgumentException;

/** A show's episodes out today: one, or several as one piece of news, and the one opening a season premieres it. */
final readonly class EpisodesReleased implements EventKind
{
    /** One episode out today. */
    public static function one(ReleaseEntry $entry, string $today): MediaEventPayload
    {
        return Released::entry($entry->opensSeason() ? MediaEventType::SeasonPremiere : MediaEventType::EpisodeAired, $entry, $today);
    }

    /**
     * Several episodes of one show out today, as one event that lists them: a
     * season dropped at once is one piece of news. It premieres the season
     * when one of them opens it, and leads with that one. Keyed by the show,
     * the day and the episodes, so a catch-up re-run says nothing new; its
     * producer leaves out the episodes already said, so the ones dated today
     * after the day's run arrive in a notification of their own.
     *
     * @param  list<ReleaseEntry>  $episodes  of one show, in season and episode order
     */
    public static function several(array $episodes, string $today): MediaEventPayload
    {
        if (count($episodes) < 2) {
            throw new InvalidArgumentException('One episode is one(); this lists several.');
        }

        $opener = array_find($episodes, fn (ReleaseEntry $entry): bool => $entry->opensSeason());
        $lead = $opener ?? $episodes[0];

        return new MediaEventPayload(
            type: $opener === null ? MediaEventType::EpisodeAired : MediaEventType::SeasonPremiere,
            key: "episodes_released:{$lead->rootId}:{$today}:".md5(implode(',', array_map(fn (ReleaseEntry $entry): string => $entry->media->ref, $episodes))),
            media: $lead->media->jsonSerialize(),
            showTitle: $lead->showTitle,
            date: $today,
            season: $lead->media->seasonNumber,
            items: array_map(fn (ReleaseEntry $entry): array => $entry->media->jsonSerialize(), $episodes),
        );
    }

    public function title(MediaEventPayload $event, string $locale): string
    {
        $show = ['title' => $event->showTitle ?? $event->title()];

        if ($event->type === MediaEventType::SeasonPremiere) {
            return __('notifications.season_premiere_of_title', $show, $locale);
        }

        return $event->listsSeveral()
            ? trans_choice('notifications.count_new_episodes_of_title', count($event->titles()), $show, $locale)
            : __('notifications.new_episode_of_title', $show, $locale);
    }

    /**
     * An episode's code and title. A show's episodes out together say only
     * what the headline does not: how many a season opened with, else the
     * finale among them.
     */
    public function detail(MediaEventPayload $event, string $locale): array
    {
        if ($event->listsSeveral()) {
            if ($event->type === MediaEventType::SeasonPremiere) {
                return [trans_choice('common.count_episode', count($event->titles()), [], $locale)];
            }

            $badges = array_map(fn (array $episode): ?EpisodeBadge => Payload::enum(Payload::map($episode['extra'] ?? null), 'episodeBadge', EpisodeBadge::class), $event->items ?? []);
            $finale = array_find($badges, fn (?EpisodeBadge $badge): bool => $badge?->isFinale() === true);

            return $finale === null ? [] : [$finale->label($locale)];
        }

        $extra = Payload::map($event->media['extra'] ?? null);
        $code = EpisodeCode::of($event->season ?? Payload::positiveInt($extra, 'seasonNumber') ?? 0, Payload::positiveInt($event->media, 'number') ?? 0);

        return $event->title() !== '' ? [$code, $event->title()] : [$code];
    }

    public function line(MediaEventPayload $event, string $locale): EventLine
    {
        return new EventLine(
            subject: $event->showTitle ?? $event->title(),
            label: match (true) {
                $event->type === MediaEventType::SeasonPremiere => __('notifications.season_premiere', [], $locale),
                $event->listsSeveral() => trans_choice('notifications.count_new_episodes', count($event->titles()), [], $locale),
                default => __('notifications.new_episode', [], $locale),
            },
            facts: $this->detail($event, $locale),
            atAirTime: true,
        );
    }
}
