<?php

use App\Catalog\Data\MediaSummary;
use App\Notifications\Events\Released;
use App\Notifications\MediaEventPayload;
use App\Tracking\Releases\ReleaseEntry;

// Media events built the way the daily release task builds them.

function premiereEvent(string $title = 'Dune', string $ref = 'tmdb:1'): MediaEventPayload
{
    return Released::of(
        new ReleaseEntry(new MediaSummary(type: 'movie', ref: $ref, provider: 'tmdb', title: $title), rootId: 1, rootType: 'movie'),
        today()->toDateString(),
    );
}
