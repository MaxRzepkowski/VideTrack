<?php

use App\Catalog\Providers\ComicVine\ComicVineServiceProvider;
use App\Catalog\Providers\Hardcover\HardcoverServiceProvider;
use App\Catalog\Providers\Igdb\IgdbServiceProvider;
use App\Catalog\Providers\Mal\MalServiceProvider;
use App\Catalog\Providers\Tmdb\TmdbServiceProvider;
use App\Catalog\Providers\Tvmaze\TvmazeServiceProvider;
use App\Catalog\Wikidata\WikidataServiceProvider;
use App\Integrations\Anime\AnimeServiceProvider;
use App\Integrations\Backloggd\BackloggdServiceProvider;
use App\Integrations\Emby\EmbyServiceProvider;
use App\Integrations\Generic\GenericServiceProvider;
use App\Integrations\Goodreads\GoodreadsServiceProvider;
use App\Integrations\Hltb\HltbServiceProvider;
use App\Integrations\Imdb\ImdbServiceProvider;
use App\Integrations\Jellyfin\JellyfinServiceProvider;
use App\Integrations\Letterboxd\LetterboxdServiceProvider;
use App\Integrations\Plex\PlexServiceProvider;
use App\Integrations\Simkl\SimklServiceProvider;
use App\Integrations\Steam\SteamServiceProvider;
use App\Integrations\StoryGraph\StoryGraphServiceProvider;
use App\Integrations\Tautulli\TautulliServiceProvider;
use App\Integrations\Tracearr\TracearrServiceProvider;
use App\Integrations\Trakt\TraktServiceProvider;
use App\Integrations\TvTime\TvTimeServiceProvider;
use App\Integrations\VideTrack\VideTrackServiceProvider;
use App\Integrations\Yamtrack\YamtrackServiceProvider;
use App\Providers\AppServiceProvider;
use App\Providers\RegistryServiceProvider;
use App\Providers\TypeScriptServiceProvider;
use App\Suggestions\SuggestionServiceProvider;
use Spatie\LaravelTypeScriptTransformer\TypeScriptTransformerApplicationServiceProvider;

return [
    AppServiceProvider::class,
    RegistryServiceProvider::class,

    // Media providers, in the order every type filter shows their types.
    TmdbServiceProvider::class,
    IgdbServiceProvider::class,
    HardcoverServiceProvider::class,
    MalServiceProvider::class,
    ComicVineServiceProvider::class,
    TvmazeServiceProvider::class,
    WikidataServiceProvider::class,

    // Integrations, in the order the import, export and integrations pages list them.
    VideTrackServiceProvider::class,
    YamtrackServiceProvider::class,
    TraktServiceProvider::class,
    LetterboxdServiceProvider::class,
    TvTimeServiceProvider::class,
    ImdbServiceProvider::class,
    GoodreadsServiceProvider::class,
    StoryGraphServiceProvider::class,
    HltbServiceProvider::class,
    BackloggdServiceProvider::class,
    SteamServiceProvider::class,
    AnimeServiceProvider::class,
    SimklServiceProvider::class,
    TracearrServiceProvider::class,
    JellyfinServiceProvider::class,
    PlexServiceProvider::class,
    TautulliServiceProvider::class,
    EmbyServiceProvider::class,
    GenericServiceProvider::class,

    SuggestionServiceProvider::class,

    // A development tool: present only where dev packages are installed.
    ...(class_exists(TypeScriptTransformerApplicationServiceProvider::class) ? [TypeScriptServiceProvider::class] : []),
];
