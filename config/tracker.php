<?php

$version = env('APP_VERSION', 'dev');

$source = env('SOURCE_URL', 'https://github.com/MaxRzepkowski/VideTrack');

$userAgent = "VideTrack/{$version} (+{$source})";

$locales = [
    'en' => ['language' => 'en-US', 'region' => 'US'],
    'pl' => ['language' => 'pl-PL', 'region' => 'PL'],
];

return [

    /*
    |--------------------------------------------------------------------------
    | Application version
    |--------------------------------------------------------------------------
    |
    | Shown on the About page. Set at image build time (release tag); "dev"
    | when running from source.
    |
    */

    'version' => $version,

    /*
    | Where the running code can be read, linked from About: the AGPL asks every
    | instance to offer its users the source. Point it at your fork when you run
    | a modified version.
    */

    'source_url' => $source,

    /*
    | The API reference linked from Settings → API. It lives with the source, so
    | a fork on another host or branch points it at its own copy.
    */

    'api_docs_url' => env('API_DOCS_URL', 'https://github.com/MaxRzepkowski/VideTrack/blob/main/docs/api.md'),

    /*
    | The MCP server at /api/mcp: an AI assistant (any MCP client) reads and
    | writes the library with an API token, under the token's abilities. It
    | has no credentials of its own, so this switch is the only way to close
    | it without revoking the tokens.
    */

    'mcp_enabled' => filter_var(env('MCP_ENABLED', true), FILTER_VALIDATE_BOOL),

    /*
    |--------------------------------------------------------------------------
    | How the instance names itself to outside services
    |--------------------------------------------------------------------------
    |
    | The User-Agent of every request to a provider or an integration. Some
    | services refuse the HTTP library's default, and the ones that publish a
    | policy ask for a client name, its version and a page about it.
    |
    */

    'http' => [
        // Not HTTP_USER_AGENT: during a web request PHP fills that name with
        // the visitor's browser header, which would go out to every service.
        'user_agent' => env('VIDETRACK_USER_AGENT', $userAgent),
    ],

    /*
    |--------------------------------------------------------------------------
    | Integration addresses
    |--------------------------------------------------------------------------
    |
    | Hosts in a private network an ordinary account may connect to (a
    | Tracearr in the local network), comma-separated, e.g.
    | "tracearr.lan,192.168.1.20". An administrator may give any address.
    |
    */

    'integrations' => [
        'private_hosts' => (string) env('INTEGRATION_PRIVATE_HOSTS', ''),
    ],

    /*
    |--------------------------------------------------------------------------
    | Registration
    |--------------------------------------------------------------------------
    |
    | When disabled, the register routes return 404 and the UI hides all
    | registration links. Existing accounts are unaffected. Unset (null),
    | sign-up is open until the first account exists, then closed.
    |
    */

    'registration_enabled' => in_array($registration = env('REGISTRATION_ENABLED'), [null, ''], true)
        ? null
        : filter_var($registration, FILTER_VALIDATE_BOOL),

    /*
    |--------------------------------------------------------------------------
    | Signing in through an identity provider
    |--------------------------------------------------------------------------
    |
    | A provider with a client id and secret shows on the sign-in page. Each
    | one is told the callback {APP_URL}/auth/{provider}/callback. The generic
    | OpenID Connect server (Keycloak, Authelia, Authentik, Pocket ID…) is
    | found from its issuer's /.well-known/openid-configuration; GitLab is
    | read the same way, from gitlab.com or the instance at GITLAB_URL.
    |
    | password_login false leaves identity providers as the only way in. It
    | holds only while at least one provider is configured, so an instance
    | cannot be locked out by a missing variable.
    |
    | Someone new gets an account through a provider exactly when sign-up is
    | open (REGISTRATION_ENABLED): with it closed, no provider opens one.
    |
    | An existing account is never joined by its address: its owner signs in
    | and connects the provider under Settings → Connected accounts.
    |
    */

    'auth' => [
        'password_login' => filter_var(env('PASSWORD_LOGIN_ENABLED', true), FILTER_VALIDATE_BOOL),
        'providers' => [
            'oidc' => [
                'issuer' => env('OIDC_ISSUER'),
                'client_id' => env('OIDC_CLIENT_ID'),
                'client_secret' => env('OIDC_CLIENT_SECRET'),
                // The server's name on the sign-in button ("Authentik"); unset,
                // the button says "single sign-on" in the reader's language.
                'label' => env('OIDC_LABEL'),
            ],
            'google' => [
                'client_id' => env('GOOGLE_CLIENT_ID'),
                'client_secret' => env('GOOGLE_CLIENT_SECRET'),
            ],
            'github' => [
                'client_id' => env('GITHUB_CLIENT_ID'),
                'client_secret' => env('GITHUB_CLIENT_SECRET'),
            ],
            'gitlab' => [
                'url' => env('GITLAB_URL', 'https://gitlab.com'),
                'client_id' => env('GITLAB_CLIENT_ID'),
                'client_secret' => env('GITLAB_CLIENT_SECRET'),
            ],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Bootstrap admin account
    |--------------------------------------------------------------------------
    |
    | On container start `php artisan app:ensure-admin` creates this account
    | if it does not exist yet and guarantees it has the admin role. The
    | password is only used at creation time; a password changed later in
    | the UI is not overwritten by subsequent starts.
    |
    */

    'admin_email' => env('ADMIN_EMAIL'),
    'admin_password' => env('ADMIN_PASSWORD'),

    /*
    |--------------------------------------------------------------------------
    | Locales
    |--------------------------------------------------------------------------
    */

    // A title whose refresh takes the worker's memory past this many MB is
    // named in the run's details, so a title that runs it out of memory
    // (256 MB, PHP's limit in the image) can be told apart.
    'heavy_title_megabytes' => 128,

    // The instance's language, the same variable `app.locale` reads: a
    // request switches `app.locale` to its viewer's, this one stays.
    'default_locale' => env('DEFAULT_LOCALE', 'en'),
    'supported_locales' => array_keys($locales),

    // What each UI language means outside the UI: the language tag providers
    // are asked in, and the country whose streaming offers, release dates and
    // age ratings a viewer sees until they pick their own.
    'locales' => $locales,

    /*
    |--------------------------------------------------------------------------
    | TMDB metadata provider
    |--------------------------------------------------------------------------
    |
    | The key never leaves the backend; every TMDB call goes through the
    | provider with response caching. Both v3 API keys and v4 read access
    | tokens are accepted. Images are hotlinked from the TMDB CDN.
    |
    */

    'tmdb' => [
        'api_key' => env('TMDB_API_KEY'),
        'base_url' => env('TMDB_BASE_URL', 'https://api.themoviedb.org/3'),
        'image_base_url' => env('TMDB_IMAGE_BASE_URL', 'https://image.tmdb.org/t/p'),
        'cache' => [
            'details' => 60 * 60 * 24, // seconds
            'dictionary' => 60 * 60 * 24 * 30, // certifications change very rarely
            'listing' => 60 * 60 * 3, // trending / discover
            'search' => 60 * 15,
            'changes' => 60 * 60, // read once a morning; an hour covers a run asked again
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Wikidata enrichment (experimental)
    |--------------------------------------------------------------------------
    |
    | Cross-cutting enrichment, not a media provider: given a title's TMDB id
    | we query the public Wikidata SPARQL endpoint for "based on" (P144) —
    | the source book/manga a film or show adapts. No API key; Wikidata only
    | asks for a descriptive User-Agent identifying the client.
    |
    */

    'wikidata' => [
        'base_url' => env('WIKIDATA_BASE_URL', 'https://query.wikidata.org'),
        // Wikidata asks for a descriptive client with a way to reach its
        // operator; an instance that queries it heavily sets its own.
        'user_agent' => env('WIKIDATA_USER_AGENT', env('VIDETRACK_USER_AGENT', $userAgent)),
        'cache' => [
            'query' => 60 * 60 * 24, // seconds
            // A failed/timed-out lookup is negative-cached for this long so a
            // slow or unreachable Wikidata blocks at most one render per window
            // instead of a fresh multi-second timeout on every page load.
            'failure' => 60 * 10, // seconds
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | IGDB metadata provider (games)
    |--------------------------------------------------------------------------
    |
    | IGDB is accessed with Twitch developer credentials: the client id and
    | secret are exchanged for an app access token on the backend (cached
    | until shortly before expiry). Images are hotlinked from the IGDB CDN.
    |
    */

    'igdb' => [
        'client_id' => env('IGDB_CLIENT_ID'),
        'client_secret' => env('IGDB_CLIENT_SECRET'),
        'base_url' => env('IGDB_BASE_URL', 'https://api.igdb.com/v4'),
        'oauth_url' => env('IGDB_OAUTH_URL', 'https://id.twitch.tv/oauth2/token'),
        'cache' => [
            'details' => 60 * 60 * 24, // seconds
            'listing' => 60 * 60 * 3, // trending / discover
            'search' => 60 * 15,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | TVmaze schedule enrichment (episode air times)
    |--------------------------------------------------------------------------
    |
    | TVmaze is a free, keyless API (~20 req / 10 s). It is not a metadata
    | provider — it only backfills the exact broadcast instant of episodes
    | whose show has a real airing slot, in a queued background job so the
    | show page render is never blocked. Streaming premieres carry no time.
    |
    */

    'tvmaze' => [
        'base_url' => env('TVMAZE_BASE_URL', 'https://api.tvmaze.com'),
        'cache' => [
            'schedule' => 60 * 60 * 12, // seconds
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Hardcover metadata provider (books)
    |--------------------------------------------------------------------------
    |
    | Hardcover serves a GraphQL API authenticated with a personal API key
    | (hardcover.app/account/api, scope read:catalog). The key never leaves the backend; every
    | call goes through the provider with response caching. Covers are
    | hotlinked from the Hardcover assets CDN.
    |
    */

    'hardcover' => [
        'api_key' => env('HARDCOVER_API_KEY'),
        'endpoint' => env('HARDCOVER_ENDPOINT', 'https://api.hardcover.app/v1/graphql'),
        // The import of a reader's own library: an OAuth app of type
        // "Mobile, desktop, or CLI" with the Device Authorization Grant on,
        // registered at hardcover.app/account/developer-apps. Users connect with a code,
        // so no callback address is needed. Without it, or without the
        // key above, the import is not offered.
        'client_id' => env('HARDCOVER_CLIENT_ID'),
        'oauth_url' => env('HARDCOVER_OAUTH_URL', 'https://api.hardcover.app'),
        'cache' => [
            'details' => 60 * 60 * 24, // seconds
            'listing' => 60 * 60 * 3, // trending / discover
            'search' => 60 * 15,
            // Hardcover recomputes a book's similar list every 7 days.
            'similar' => 60 * 60 * 24 * 7,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | MyAnimeList metadata provider (manga)
    |--------------------------------------------------------------------------
    |
    | Public MAL API v2 data only needs the client id (X-MAL-CLIENT-ID
    | header) — no OAuth dance. Covers are hotlinked from the MAL CDN.
    |
    */

    'mal' => [
        'client_id' => env('MAL_CLIENT_ID'),
        'base_url' => env('MAL_BASE_URL', 'https://api.myanimelist.net/v2'),
        'cache' => [
            'details' => 60 * 60 * 24, // seconds
            'listing' => 60 * 60 * 3, // trending / discover
            'search' => 60 * 15,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | MDBList (import through a connected account)
    |--------------------------------------------------------------------------
    |
    | The instance owner registers one "Device Code App" at
    | https://mdblist.com/developer and puts its client id here. Users
    | connect with a code, so no callback address is needed. Without a client
    | id the MDBList import is not offered.
    |
    */

    'mdblist' => [
        'client_id' => env('MDBLIST_CLIENT_ID'),
        'base_url' => env('MDBLIST_BASE_URL', 'https://api.mdblist.com'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Simkl (import through a connected account)
    |--------------------------------------------------------------------------
    |
    | The instance owner registers one AUTH V2 app of type "TV, devices &
    | command line" at https://simkl.com/settings/developer/ and puts its
    | client id here. The device flow needs no secret and no callback
    | address, so this works on an instance that answers only on localhost.
    | Without a client id the Simkl source is not offered.
    |
    */

    'simkl' => [
        'client_id' => env('SIMKL_CLIENT_ID'),
        'base_url' => env('SIMKL_BASE_URL', 'https://api.simkl.com'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Steam (import of a public game library)
    |--------------------------------------------------------------------------
    |
    | One Steam Web API key for the instance (free, 100,000 calls a day,
    | https://steamcommunity.com/dev/apikey). A user names their profile; the
    | library is one request. Games are placed through IGDB, so the source is
    | offered only when IGDB is configured too.
    |
    */

    'steam' => [
        'api_key' => env('STEAM_API_KEY'),
        'base_url' => env('STEAM_BASE_URL', 'https://api.steampowered.com'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Apprise (notification channels to many services)
    |--------------------------------------------------------------------------
    |
    | The address of an apprise-api container (https://github.com/caronc/apprise-api).
    | Set, every service it delivers to (Telegram, ntfy, e-mail, Slack…) is a
    | kind of notification channel; unset, none of them is offered.
    |
    */

    'apprise' => [
        'url' => env('APPRISE_API_URL'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Web push (notifications in the browser and the installed app)
    |--------------------------------------------------------------------------
    |
    | The instance signs every push with a key pair (VAPID). Without one given
    | here, the first start makes a pair and keeps it in `keys_path`; back that
    | file up with the database, since a new pair leaves every browser to be
    | switched on again. The subject is how a push service reaches the
    | operator: an https: address or a mailto:. Left empty, it is APP_URL when
    | that is https, else the source address.
    |
    */

    'webpush' => [
        'public_key' => env('VAPID_PUBLIC_KEY'),
        'private_key' => env('VAPID_PRIVATE_KEY'),
        'subject' => env('VAPID_SUBJECT'),
        'keys_path' => env('VAPID_KEYS_PATH', storage_path('app/private/vapid.json')),
    ],

    /*
    |--------------------------------------------------------------------------
    | ComicVine metadata provider (comics)
    |--------------------------------------------------------------------------
    |
    | ComicVine authenticates with an api_key query parameter and requires a
    | custom User-Agent. The hourly quota is tight (200 requests per
    | resource), so responses are cached and the client fails fast when the
    | local budget runs out instead of queueing up.
    |
    */

    'comicvine' => [
        'api_key' => env('COMICVINE_API_KEY'),
        'base_url' => env('COMICVINE_BASE_URL', 'https://comicvine.gamespot.com/api'),
        'cache' => [
            'details' => 60 * 60 * 24, // seconds
            'listing' => 60 * 60 * 3, // trending / discover
            'search' => 60 * 15,
            // A volume ComicVine no longer has (status 101) is asked about by
            // every refresh; a short memory keeps it off the hourly budget.
            'not_found' => 60 * 10,
            'changes' => 60 * 60, // read once a morning; an hour covers a run asked again
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Incoming scrobbles (media servers, readers)
    |--------------------------------------------------------------------------
    |
    | A media server reports a stop, not a verdict: credits, a skipped outro
    | or simply closing the app all arrive the same way. The threshold is
    | where "stopped" becomes "watched" — 85% is the convention the ecosystem
    | settled on. The dedup window keeps two servers reporting the same
    | viewing from logging it twice.
    |
    */

    'scrobble' => [
        'completion_threshold' => (int) env('SCROBBLE_COMPLETION_THRESHOLD', 85),
        'dedup_window_hours' => (int) env('SCROBBLE_DEDUP_WINDOW_HOURS', 6),
        // Hosts a media server in a container of its own hears as itself: a
        // webhook address on one of them never leaves the sender. `app` is
        // this instance's service name in the shipped docker-compose.yml.
        'loopback_hosts' => ['localhost', '127.0.0.1', '::1', '0.0.0.0', 'app'],
        // Addresses one account may have for one server: enough for every
        // server and player in a home, short of an unbounded pile of tokens.
        'endpoints_per_source' => 10,
    ],

    /*
    |--------------------------------------------------------------------------
    | How long the instance keeps what it writes for itself
    |--------------------------------------------------------------------------
    |
    | The daily cleanup task prunes these and reports what it removed.
    | Notifications are a feed, not an archive — the activity pages hold the
    | durable history. An integration's delivery log fails two ways: it goes
    | stale, and a chatty server buries yesterday under today, so it has an
    | age and a per-endpoint cap.
    |
    */

    'retention' => [
        'task_runs_days' => 30,
        'failed_jobs_days' => 30,
        'notifications_days' => 30,
        'webhook_deliveries_days' => (int) env('SCROBBLE_DELIVERY_RETENTION_DAYS', 30),
        'webhook_deliveries_per_endpoint' => 100,
    ],

    // Showcases and shows of a medium (Summer Game Fest, a Nintendo Direct).
    'events' => [
        // How long a show counts as on the air where its provider names no
        // end: the "live" marks, the countdowns and the calendar's end time
        // fall back on it.
        'fallback_minutes' => (int) env('TRACKER_EVENTS_FALLBACK_MINUTES', 30),
        // How far ahead the "live" button is told of an event, so it switches
        // itself on at the start on a page opened earlier.
        'live_lookahead_hours' => (int) env('TRACKER_EVENTS_LIVE_LOOKAHEAD_HOURS', 12),
    ],

    /*
    |--------------------------------------------------------------------------
    | Suggestions
    |--------------------------------------------------------------------------
    |
    | The bars a title or a person clears to be put forward: looser and the
    | shelves fill with titles nobody has heard of, tighter and they stay empty.
    | Set from measurements on a real library, not guessed.
    |
    */

    'suggestions' => [
        // Ratings a title needs at its provider to count into a person's
        // work at all: below it are shorts, music videos and festival films.
        'min_votes' => 300,
        // Titles read from the top of each type's chart, per shelf: deep enough
        // that someone well-watched still finds things, shallow enough that
        // the bottom of the list is still a title people have heard of.
        'chart_depth' => ['best_known' => 500, 'top_rated' => 250, 'per_year' => 60, 'per_decade' => 100],
        // People whose work is fetched per shelf, the best fits first.
        'people_per_shelf' => 150,
        'directors' => ['min_finished' => 3, 'min_share' => 0.5],
        'writers' => ['min_finished' => 3, 'min_share' => 0.5],
        'actors' => [
            // Deeper in the cast than this is a cameo, not "a film with them".
            'max_billing' => 8,
            'min_rated' => 4,
            // An actor's average is pulled toward the user's own by this many
            // imaginary average films, so two lucky hits do not make a favourite.
            'prior_films' => 5,
            // How far above the user's own average the pulled average must sit.
            'margin' => 0.4,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Rate limits (requests a minute)
    |--------------------------------------------------------------------------
    |
    | Ceilings for a runaway tab or script, not a pace a person reaches. The
    | API and the incoming webhooks are what scripts and media servers call,
    | so those two can be raised for a busy instance.
    |
    */

    'rate_limits' => [
        // Everything a signed-in person clicks.
        'web' => 240,
        // Pages that fan out to an upstream provider.
        'provider_pages' => 120,
        'api' => (int) env('API_RATE_LIMIT_PER_MINUTE', 240),
        'webhooks_per_endpoint' => (int) env('WEBHOOK_RATE_LIMIT_PER_MINUTE', 60),
        'webhooks_per_ip' => 300,
        'ical_per_feed' => 30,
        'ical_per_ip' => 120,
        // Forms an anonymous visitor can submit in a loop.
        'account_forms' => 6,
        // Settings actions that read or write a whole library.
        'library_heavy' => 10,
        // A test message to one of the user's notification channels: a
        // service blocks an address that sends too many.
        'channel_test' => 5,
        // A test message to one of the user's own webhooks: their own
        // receiver blocks nobody, so it may take more than a service.
        'webhook_test' => 10,
        // A webhook dry run, which reaches out to the metadata provider.
        'integration_test' => 20,
        // A connect card waiting on a device flow polls every few seconds.
        'connections' => 60,
        // Sign-in attempts from one address across every e-mail: the form's
        // own throttle counts each e-mail apart, which a spray walks around.
        'login_per_ip' => 30,
        // Wrong passwords for one account from any address, within the
        // window below: a spray from many addresses (or forged ones) still
        // meets a wall.
        'login_per_account' => 30,
        'login_per_account_window_minutes' => 60,
        // Trips to an identity provider and back, per address: each one is a
        // request to the provider from the instance's address.
        'identity_sign_in' => 20,
    ],

    /*
    |--------------------------------------------------------------------------
    | Imports from anime and manga trackers
    |--------------------------------------------------------------------------
    |
    | AniList and Kitsu serve public libraries without a key; MyAnimeList
    | uses the MAL client id above. Anime is tracked as TMDB shows and films,
    | so it is placed through the Kometa anime id map — pinned to one
    | revision in App\Integrations\Anime\AnimeIdMap, fetched from this host.
    |
    */

    'imports' => [
        'anilist_url' => env('ANILIST_GRAPHQL_URL', 'https://graphql.anilist.co'),
        'kitsu_url' => env('KITSU_BASE_URL', 'https://kitsu.app/api/edge'),
        'anime_ids_url' => env('ANIME_IDS_BASE_URL', 'https://raw.githubusercontent.com/Kometa-Team/Anime-IDs'),
        // The largest single file an import reads, uncompressed. A file is
        // read whole, and a 50 MB ZIP or gzip of history can unpack to
        // hundreds of MB; past this, the import stops at analysis instead of
        // the worker running out of memory.
        'max_unpacked_megabytes' => 64,
    ],

];
