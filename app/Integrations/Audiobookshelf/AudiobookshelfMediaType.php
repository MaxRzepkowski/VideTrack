<?php

namespace App\Integrations\Audiobookshelf;

/** What an Audiobookshelf media progress is about (its `mediaItemType`). */
enum AudiobookshelfMediaType: string
{
    case Book = 'book';
    case PodcastEpisode = 'podcastEpisode';
}
