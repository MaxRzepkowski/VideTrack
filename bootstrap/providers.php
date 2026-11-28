<?php

use App\Accounts\Identity\IdentityServiceProvider;
use App\Catalog\Providers\ComicVine\ComicVineServiceProvider;
use App\Catalog\Providers\Hardcover\HardcoverServiceProvider;
use App\Catalog\Providers\Igdb\IgdbServiceProvider;
use App\Catalog\Providers\Mal\MalServiceProvider;
use App\Catalog\Providers\Tmdb\TmdbServiceProvider;
use App\Catalog\Providers\Tvmaze\TvmazeServiceProvider;
use App\Catalog\Wikidata\WikidataServiceProvider;
use App\Integrations\Anime\AnimeServiceProvider;
use App\Integrations\AnimePlanet\AnimePlanetServiceProvider;
use App\Integrations\Apprise\AppriseServiceProvider;
use App\Integrations\Audiobookshelf\AudiobookshelfServiceProvider;
use App\Integrations\Backloggd\BackloggdServiceProvider;
use App\Integrations\BookWyrm\BookWyrmServiceProvider;
use App\Integrations\Calibre\CalibreServiceProvider;
use App\Integrations\Criticker\CritickerServiceProvider;
use App\Integrations\Discord\DiscordServiceProvider;
use App\Integrations\Emby\EmbyServiceProvider;
use App\Integrations\Generic\GenericServiceProvider;
use App\Integrations\GogGalaxy\GogGalaxyServiceProvider;
use App\Integrations\Goodreads\GoodreadsServiceProvider;
use App\Integrations\Grouvee\GrouveeServiceProvider;
use App\Integrations\Hardcover\HardcoverLibraryServiceProvider;
use App\Integrations\Hltb\HltbServiceProvider;
use App\Integrations\ICheckMovies\ICheckMoviesServiceProvider;
use App\Integrations\Imdb\ImdbServiceProvider;
use App\Integrations\Jellyfin\JellyfinServiceProvider;
use App\Integrations\Kavita\KavitaServiceProvider;
use App\Integrations\KOReader\KOReaderServiceProvider;
use App\Integrations\Letterboxd\LetterboxdServiceProvider;
use App\Integrations\LibraryThing\LibraryThingServiceProvider;
use App\Integrations\Mail\MailServiceProvider;
use App\Integrations\MangaBaka\MangaBakaLibraryServiceProvider;
use App\Integrations\MdbList\MdbListServiceProvider;
use App\Integrations\Netflix\NetflixServiceProvider;
use App\Integrations\Nuvio\NuvioServiceProvider;
use App\Integrations\OutgoingWebhooks\OutgoingWebhooksServiceProvider;
use App\Integrations\Playnite\PlayniteServiceProvider;
use App\Integrations\Plex\PlexServiceProvider;
use App\Integrations\RetroAchievements\RetroAchievementsServiceProvider;
use App\Integrations\Simkl\SimklServiceProvider;
use App\Integrations\Steam\SteamServiceProvider;
use App\Integrations\StoryGraph\StoryGraphServiceProvider;
use App\Integrations\Storyteller\StorytellerServiceProvider;
use App\Integrations\Tautulli\TautulliServiceProvider;
use App\Integrations\Tmdb\TmdbAccountServiceProvider;
use App\Integrations\Tracearr\TracearrServiceProvider;
use App\Integrations\Trakt\TraktServiceProvider;
use App\Integrations\TvTime\TvTimeServiceProvider;
use App\Integrations\VideTrack\VideTrackServiceProvider;
use App\Integrations\WebPush\WebPushServiceProvider;
use App\Integrations\WeTrakr\WeTrakrServiceProvider;
use App\Integrations\Yamtrack\YamtrackServiceProvider;
use App\Notifications\NotificationsServiceProvider;
use App\Providers\AppServiceProvider;
use App\Providers\RegistryServiceProvider;
use App\Providers\TypeScriptServiceProvider;
use App\Suggestions\SuggestionServiceProvider;
use App\Tasks\Maintenance\MaintenanceServiceProvider;
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
    TmdbAccountServiceProvider::class,
    WeTrakrServiceProvider::class,
    LetterboxdServiceProvider::class,
    TvTimeServiceProvider::class,
    NetflixServiceProvider::class,
    ImdbServiceProvider::class,
    CritickerServiceProvider::class,
    ICheckMoviesServiceProvider::class,
    GoodreadsServiceProvider::class,
    StoryGraphServiceProvider::class,
    BookWyrmServiceProvider::class,
    KOReaderServiceProvider::class,
    HardcoverLibraryServiceProvider::class,
    LibraryThingServiceProvider::class,
    CalibreServiceProvider::class,
    HltbServiceProvider::class,
    BackloggdServiceProvider::class,
    PlayniteServiceProvider::class,
    GogGalaxyServiceProvider::class,
    GrouveeServiceProvider::class,
    SteamServiceProvider::class,
    RetroAchievementsServiceProvider::class,
    AnimeServiceProvider::class,
    AnimePlanetServiceProvider::class,
    MangaBakaLibraryServiceProvider::class,
    SimklServiceProvider::class,
    MdbListServiceProvider::class,
    TracearrServiceProvider::class,
    NuvioServiceProvider::class,
    AudiobookshelfServiceProvider::class,
    StorytellerServiceProvider::class,
    KavitaServiceProvider::class,
    JellyfinServiceProvider::class,
    PlexServiceProvider::class,
    TautulliServiceProvider::class,
    EmbyServiceProvider::class,
    GenericServiceProvider::class,

    // What notifications say, and where they go beyond the bell.
    NotificationsServiceProvider::class,
    DiscordServiceProvider::class,
    MailServiceProvider::class,
    AppriseServiceProvider::class,
    WebPushServiceProvider::class,

    // Where the library's changes go: addresses of the user's own.
    OutgoingWebhooksServiceProvider::class,

    SuggestionServiceProvider::class,

    // The instance's own upkeep.
    MaintenanceServiceProvider::class,

    // The identity providers the operator configured, for signing in.
    IdentityServiceProvider::class,

    // A development tool: present only where dev packages are installed.
    ...(class_exists(TypeScriptTransformerApplicationServiceProvider::class) ? [TypeScriptServiceProvider::class] : []),
];
