# Installing VideTrack

VideTrack runs as one container: the web server, the queue worker and the scheduler, with the
library in one SQLite file. The [quick start](../README.md#install) gets it running
on one machine; this page covers putting it on the network, every setting, and building the
image yourself.

## Files

Two files in one directory:

- [`compose.yaml`](../deploy/compose.yaml): the service, its port and its two volumes;
- `.env`, copied from [`env.example`](../deploy/env.example): your settings.

```sh
docker compose up -d        # start, or apply a changed .env
docker compose logs -f      # what the container says
docker compose ps           # "healthy" once the app answers
```

The `videtrack-data` volume holds the database, its pre-upgrade backups and the generated app key and
web push keys;
`videtrack-storage` holds avatars, exports and caches.

## On the network

`compose.yaml` binds the port to `127.0.0.1:8080`, for a reverse proxy on the same machine that
serves the instance over HTTPS. Set `APP_URL` to the address people open (`https://tracker.example.com`):
links that go to someone else (a password-reset e-mail, a Discord message) are built from it, and an
`https://` address keeps the session cookie on HTTPS.

The app believes `X-Forwarded-*` headers only from private addresses (`TRUSTED_PROXIES`), which is
where a proxy on the host or in the Docker network connects from. A visitor from the internet keeps
its own address, so the login throttle cannot be dodged with a forged header. A proxy on a public
address (another server, a tunnel) must be listed in `TRUSTED_PROXIES`.

### Caddy

```caddy
tracker.example.com {
    reverse_proxy 127.0.0.1:8080
}
```

### Traefik

Remove the `ports:` section from `compose.yaml`, put the service on Traefik's network and add:

```yaml
    labels:
      - traefik.enable=true
      - traefik.http.routers.videtrack.rule=Host(`tracker.example.com`)
      - traefik.http.routers.videtrack.entrypoints=websecure
      - traefik.http.routers.videtrack.tls.certresolver=letsencrypt
      - traefik.http.services.videtrack.loadbalancer.server.port=80
```

### nginx

```nginx
location / {
    proxy_pass http://127.0.0.1:8080;
    proxy_set_header Host $host;
    proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
    proxy_set_header X-Forwarded-Proto $scheme;
    proxy_set_header X-Forwarded-Host $host;
    client_max_body_size 64m;
}
```

`client_max_body_size` lets an import file of up to 50 MB through.

### A home network without a proxy

Change the port mapping to `"8080:80"` and open `http://<server-address>:8080`. Keep `APP_URL` on
`http://`: with `https://` the session cookie would only travel over HTTPS and signing in over
plain HTTP would not work.

Installing VideTrack as an app on a phone or computer also needs HTTPS: browsers offer it only over
HTTPS or on `localhost`, and the same goes for [notifications in the browser](#notifications-in-the-browser).
Over plain HTTP the site works as usual in the browser.

## Accounts

The first account registered becomes the administrator and sign-up closes after it. To keep
sign-up open for your household, set `REGISTRATION_ENABLED=true`; `false` keeps it closed even on
an empty instance. Instead of registering, `ADMIN_EMAIL` and `ADMIN_PASSWORD` create the
administrator on every start (it is never created twice). Accounts are managed with commands; see
[Administration](administration.md).

## Signing in with an identity provider

People can also log in through your own OpenID Connect server (Keycloak, Authelia, Authentik,
Pocket ID and the like), Google, GitHub or GitLab. A provider whose client id and secret are set
shows on the login page; register VideTrack with it as a web application and give it the callback
address `<APP_URL>/auth/<provider>/callback`, where `<provider>` is `oidc`, `google`, `github` or
`gitlab`. `APP_URL` has to be the address people open, because the way back from the provider
returns there.

| Provider | Variables | Where to get them |
|---|---|---|
| OpenID Connect | `OIDC_ISSUER`, `OIDC_CLIENT_ID`, `OIDC_CLIENT_SECRET`, optional `OIDC_LABEL` | your server's client settings; the issuer is the address its `/.well-known/openid-configuration` hangs off. `OIDC_LABEL` names the button ("Authentik"), otherwise it says single sign-on |
| Google | `GOOGLE_CLIENT_ID`, `GOOGLE_CLIENT_SECRET` | an OAuth client of type *Web application* in the [Google Cloud console](https://console.cloud.google.com/apis/credentials) |
| GitHub | `GITHUB_CLIENT_ID`, `GITHUB_CLIENT_SECRET` | an [OAuth app](https://github.com/settings/developers) |
| GitLab | `GITLAB_CLIENT_ID`, `GITLAB_CLIENT_SECRET`, optional `GITLAB_URL` | an application with the scopes `openid`, `profile` and `email` under *Preferences → Applications*; `GITLAB_URL` points at your own GitLab |

Who gets in:

- Someone who already has an account connects a provider under Settings → Connected accounts and
  logs in with it from then on.
- Someone new gets an account on their first login while sign-up is open (`REGISTRATION_ENABLED`),
  exactly as through the sign-up form; with sign-up closed no provider makes one. A new account
  needs an email address the provider has verified; it has no password until its owner sets one.
- A first login whose address already has an account is refused: the owner logs in with their
  password and connects the provider. Accounts are never joined by address alone, since VideTrack
  does not confirm the address an account was given.

`PASSWORD_LOGIN_ENABLED=false` makes the providers the only way in: the password form, sign-up,
password reset and the password settings go away. Existing accounts get in only through a
provider they connected beforehand, so connect them (the administrator's too) before switching
passwords off. While no provider is configured the setting is ignored, so a typo cannot lock
everyone out; the container log says so on every start.

## Settings

Everything is optional except `TMDB_API_KEY`. A media type exists only when its keys are set.

| Variable | Default | Description |
|---|---|---|
| `TMDB_API_KEY` | (required) | TMDB API key (v3) or read token (v4): movies and TV. Users can also connect their own TMDB account with it to import their watchlist, favorites, ratings and public lists; nothing else to register |
| `APP_URL` | `http://localhost:8080` | the address people open; links sent to others and the webhook addresses on Settings → Integrations are built from it, so a media server in another container needs an address it can reach |
| `APP_KEY` | generated | encryption key for stored tokens and sessions; generated on first start into `/data/app.key`. Set it only to bring your own |
| `IGDB_CLIENT_ID` / `IGDB_CLIENT_SECRET` | | [Twitch Developers](https://dev.twitch.tv/console/apps) app credentials: **games** |
| `HARDCOVER_API_KEY` | | [Hardcover](https://hardcover.app/account/api) API key (`hc_pat_…`, scope `read:catalog`): **books** |
| `MAL_CLIENT_ID` | | [MyAnimeList](https://myanimelist.net/apiconfig) client id: **manga** |
| `COMICVINE_API_KEY` | | [ComicVine](https://comicvine.gamespot.com/api) key: **comics** |
| `SIMKL_CLIENT_ID` | | client id of a Simkl **AUTH V2** app of type *TV, devices & command line* ([developer settings](https://simkl.com/settings/developer/)); users connect with a code, no callback address. Without it the Simkl import is not offered |
| `VAPID_PUBLIC_KEY` / `VAPID_PRIVATE_KEY` | generated | the key pair browser notifications are signed with; generated on first start into `/data/vapid.json`. Set them only to bring your own pair ([below](#notifications-in-the-browser)) |
| `VAPID_SUBJECT` | `APP_URL` if it is `https://`, else `SOURCE_URL` | how a push service may reach you about this instance's pushes: an `https://` address or `mailto:` |
| `APPRISE_API_URL` | `http://apprise:8000` in `compose.yaml` | address of the apprise-api container; empty, its services are not offered as notification channels ([below](#notifications-through-apprise)) |
| `REGISTRATION_ENABLED` | open until the first account | `true` keeps sign-up open, `false` closed |
| `ADMIN_EMAIL` / `ADMIN_PASSWORD` | | an administrator created on start |
| `OIDC_ISSUER` / `OIDC_CLIENT_ID` / `OIDC_CLIENT_SECRET` / `OIDC_LABEL` | | logging in through your own OpenID Connect server ([above](#signing-in-with-an-identity-provider)) |
| `GOOGLE_CLIENT_ID` / `GOOGLE_CLIENT_SECRET` | | logging in with Google |
| `GITHUB_CLIENT_ID` / `GITHUB_CLIENT_SECRET` | | logging in with GitHub |
| `GITLAB_CLIENT_ID` / `GITLAB_CLIENT_SECRET` / `GITLAB_URL` | `GITLAB_URL`: `https://gitlab.com` | logging in with GitLab |
| `PASSWORD_LOGIN_ENABLED` | `true` | `false` leaves the providers as the only way in (ignored while none is configured) |
| `DEFAULT_LOCALE` | `en` | language of new accounts (`en` / `pl`); each user picks their own |
| `APP_TIMEZONE` | `UTC` | storage time zone; display uses each user's preference |
| `MAIL_MAILER` | `log` | how the instance sends mail: password-reset links, and the e-mail notification channel, offered only when mail really goes out. `log` only writes it to the container log. `smtp` with `MAIL_HOST`, `MAIL_PORT` (`587`), `MAIL_USERNAME`, `MAIL_PASSWORD`, `MAIL_SCHEME` |
| `MAIL_FROM_ADDRESS` | `videtrack@localhost` | sender of the mail the instance sends |
| `TRUSTED_PROXIES` | `PRIVATE_SUBNETS` | whose `X-Forwarded-*` headers to believe (client address for throttling, HTTPS detection); list a proxy on a public address here. `*` is right only when nothing but the proxy can reach the app |
| `SESSION_SECURE_COOKIE` | follows `APP_URL` | `true` sends the session cookie over HTTPS only; unset, it is `true` exactly when `APP_URL` starts with `https://` |
| `SESSION_LIFETIME` | `43200` (30 days) | minutes of inactivity before a session ends; "remember me" keeps it about five years |
| `INTEGRATION_PRIVATE_HOSTS` | | private-network hosts an ordinary account may connect an integration or point a notification channel to (an administrator may use any), comma-separated: `tracearr.lan,192.168.1.20` |
| `LOG_CHANNEL` / `LOG_LEVEL` | `stderr` / `warning` | `stderr` writes to `docker logs`; `daily` or `single` write a file on the storage volume |
| `SOURCE_URL` | this repository | where About links for the source code; point it at your fork when you run a modified version (AGPL-3.0, section 13) |
| `API_DOCS_URL` | `docs/api.md` in this repository | the API reference linked from Settings → API; point it at your fork's copy |
| `VIDETRACK_USER_AGENT` | `VideTrack/<version> (+SOURCE_URL)` | how the instance names itself to providers and integrations |
| `WIKIDATA_USER_AGENT` | same as above | Wikidata asks heavy clients for a descriptive name with a contact |
| `SCROBBLE_COMPLETION_THRESHOLD` | `85` | percent of a title a media server must report before a stop counts as watched |
| `SCROBBLE_DEDUP_WINDOW_HOURS` | `6` | two servers reporting one viewing within this window log one play |
| `SCROBBLE_DELIVERY_RETENTION_DAYS` | `30` | how long the delivery log of an integration is kept |
| `MCP_ENABLED` | `true` | `false` closes the MCP server for AI assistants at `/api/mcp` ([API](api.md#mcp-server-for-ai-assistants)); the API itself stays open |
| `API_RATE_LIMIT_PER_MINUTE` | `240` | requests a minute one account may send to the API, and apart from them to the MCP server |
| `WEBHOOK_RATE_LIMIT_PER_MINUTE` | `60` | reports a minute one integration's webhook address may receive |

## Notifications through Apprise

On Settings → Notifications every user picks where their notifications go besides the bell: Discord,
e-mail sent by this instance (when [mail is set up](#settings)), and every service
[Apprise](https://github.com/caronc/apprise) delivers to: Telegram, ntfy, Signal, Matrix, Slack,
Gotify, Pushover, e-mail through any mail server and [many more](https://github.com/caronc/apprise/wiki).
Apprise runs as the second container in [`compose.yaml`](../deploy/compose.yaml)
([apprise-api](https://github.com/caronc/apprise-api)), and `APPRISE_API_URL` there points VideTrack
at it: nothing to set up. The list of services comes from that container, so a service Apprise adds
appears with its next image.

A user fills in the fields the service asks for (a bot token, a topic, a server…), and VideTrack puts
the Apprise URL together. A field that names a server follows the same rule as the integrations: an
ordinary account may not point it into a private network unless `INTEGRATION_PRIVATE_HOSTS` lists the
host; an administrator may use any. The check runs when the channel is saved; Apprise looks the name up
again when it sends.

Without the container, delete the `apprise` service and the `APPRISE_API_URL` line from
`compose.yaml`: its services disappear from the settings, and Discord and e-mail stay.

## Notifications in the browser

Notifications also show up in the browser and in the installed app, on a phone or a computer. Each
user turns them on per device under Settings → Notifications; the browser then asks for permission.
On iPhone and iPad this works only in the app added to the Home Screen (iOS 16.4 and later).

It needs HTTPS (or `localhost`), and nothing else: the first start makes the key pair the instance
signs every message with and keeps it in `/data/vapid.json`. Back it up with the database
([Backup](administration.md#backup)): with a new pair, the browsers subscribed with the old one hear
nothing until they are turned on again. An open app does that by itself where the browser allows it
without a click; elsewhere the device shows up on the list as needing to be turned on again. The
messages travel through the push service of each browser (Google's, Mozilla's, Apple's),
encrypted so that only the browser can read them.

## Building the image yourself

```sh
git clone https://github.com/MaxRzepkowski/VideTrack.git && cd VideTrack
docker build -f docker/prod/Dockerfile --build-arg APP_VERSION=local -t videtrack:local .
```

Then set `image: videtrack:local` in your `compose.yaml`.
