<?php

namespace App\Notifications;

use App\Accounts\Preferences\NotificationEvent;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/** What a notification is about: always a fact from outside, never a prompt. */
#[TypeScript]
enum MediaEventType: string
{
    case EpisodeAired = 'episode_aired';
    case SeasonPremiere = 'season_premiere';
    case Premiere = 'premiere';
    /** A film leaving cinemas for home release. */
    case HomeRelease = 'home_release';
    case DateChanged = 'date_changed';
    case ShowStatus = 'show_status';
    case SeasonAnnounced = 'season_announced';
    case PersonNewTitle = 'person_new_title';
    case CompanyNewTitle = 'company_new_title';
    case CollectionNewItem = 'collection_new_item';
    /** Titles of the user's library their source no longer has. */
    case TitlesRemoved = 'titles_removed';

    /**
     * The switch that silences it; null where none does — a home release
     * answers to the release mode the feed already applied, and losing titles
     * from the library is always said.
     */
    public function preference(): ?NotificationEvent
    {
        return match ($this) {
            self::EpisodeAired, self::SeasonPremiere => NotificationEvent::Episodes,
            self::Premiere => NotificationEvent::Premieres,
            self::HomeRelease, self::TitlesRemoved => null,
            self::DateChanged => NotificationEvent::DateChanges,
            // A new season is news about the show, as its status is.
            self::ShowStatus, self::SeasonAnnounced => NotificationEvent::ShowStatus,
            self::PersonNewTitle => NotificationEvent::PersonTitles,
            self::CompanyNewTitle => NotificationEvent::CompanyTitles,
            self::CollectionNewItem => NotificationEvent::CollectionItems,
        };
    }

    /** Whether it announces a title a followed person, company or collection brought in. */
    public function comesFromCreators(): bool
    {
        return in_array($this, [self::PersonNewTitle, self::CompanyNewTitle, self::CollectionNewItem], true);
    }
}
