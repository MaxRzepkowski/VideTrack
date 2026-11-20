<?php

namespace App\Notifications;

use App\Notifications\Events\CollectionParts;
use App\Notifications\Events\CreatorNewTitle;
use App\Notifications\Events\DateChanged;
use App\Notifications\Events\EpisodesReleased;
use App\Notifications\Events\EventKinds;
use App\Notifications\Events\HomeRelease;
use App\Notifications\Events\Premiere;
use App\Notifications\Events\RelatedAnnounced;
use App\Notifications\Events\SeasonAnnounced;
use App\Notifications\Events\ShowStatus;
use App\Notifications\Events\TitlesRemoved;
use Illuminate\Support\ServiceProvider;

/** The kinds of media event a notification can be about, each with the class that builds and words it. */
final class NotificationsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->callAfterResolving(EventKinds::class, function (EventKinds $kinds): void {
            $kinds->register(MediaEventType::EpisodeAired->value, EpisodesReleased::class);
            $kinds->register(MediaEventType::SeasonPremiere->value, EpisodesReleased::class);
            $kinds->register(MediaEventType::Premiere->value, Premiere::class);
            $kinds->register(MediaEventType::HomeRelease->value, HomeRelease::class);
            $kinds->register(MediaEventType::DateChanged->value, DateChanged::class);
            $kinds->register(MediaEventType::ShowStatus->value, ShowStatus::class);
            $kinds->register(MediaEventType::SeasonAnnounced->value, SeasonAnnounced::class);
            $kinds->register(MediaEventType::RelatedAnnounced->value, RelatedAnnounced::class);
            $kinds->register(MediaEventType::PersonNewTitle->value, CreatorNewTitle::class);
            $kinds->register(MediaEventType::CompanyNewTitle->value, CreatorNewTitle::class);
            $kinds->register(MediaEventType::CollectionNewItem->value, CollectionParts::class);
            $kinds->register(MediaEventType::TitlesRemoved->value, TitlesRemoved::class);
        });
    }
}
