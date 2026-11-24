# Upgrading VideTrack

Versions follow [SemVer](https://semver.org/). While the version starts with `0.`, a minor
release (`0.2.0`) may change behaviour; the [changelog](../CHANGELOG.md) says what changed.

## Upgrade

```sh
docker compose pull
docker compose up -d
```

On start the container:

1. takes a consistent snapshot of the database into `/data/backups/pre-migrate-<time>.sqlite`
   (the last five are kept), and refuses to start if it cannot;
2. runs the migrations;
3. rebuilds the library's read model and catches up on scheduled work it missed while it was down.

`docker compose ps` shows `healthy` once the app answers.

## Choosing when to upgrade

`compose.yaml` follows `latest`, the newest release. To upgrade only when you decide, pin a
version in `.env`:

```sh
VIDETRACK_VERSION=0.1      # 0.1.x patches only
```

Images are tagged `X.Y.Z`, `X.Y` and `latest`, one set per release.

## Settings that changed

- **`HTTP_USER_AGENT` is now `VIDETRACK_USER_AGENT`.** If you set it in `.env`, rename it. During a
  web request PHP fills `HTTP_USER_AGENT` with the visitor's browser header, so an instance running
  without a cached configuration sent that header to the services it talks to.

- **`docker compose pull` updates images, not your `compose.yaml`.** The services next to VideTrack
  are in [`deploy/compose.yaml`](../deploy/compose.yaml): the `apprise` container, which sends
  notifications to Telegram, ntfy, e-mail and more, with the `APPRISE_API_URL` line that names it.
  Without them the instance offers Discord and e-mail alone; copy both into your file to offer the
  Apprise services too.
- **The `apprise` container is pinned to a minor version** (`caronc/apprise:2.1`): `docker compose pull`
  brings its patches. A newer minor, with the services Apprise added, is the tag you set in `compose.yaml`.
- **`INTEGRATION_PRIVATE_HOSTS` is needed only for the server itself.** Every account may now give an
  address in the home network (a Kavita, a Home Assistant), so hosts listed there for that reason can go.
  An address that leads to the server VideTrack runs on (`localhost`, another container of the compose
  file, a cloud's metadata endpoint) stays for an administrator, or a host listed there; webhooks and
  browser notifications follow the same rule, checked again before every message. A webhook of an
  ordinary account that points at such an address (`http://homeassistant:8123` in the same compose
  file), or at the home network of an instance on the host's network (`network_mode: host`), says on
  Settings → Webhooks that it is not delivered: list its host there to keep it. The same goes for a
  container name in a network VideTrack shares with other services (a reverse proxy's): an ordinary
  account gives the service's outside or home-network address instead, or the host is listed.

## Going back

A migration changes the database, so an older image cannot simply run on it. To return to the
version you came from:

```sh
docker compose stop
docker compose run --rm --entrypoint sh videtrack -c 'ls -1t /data/backups/'
# Pick the snapshot taken at the failed upgrade, then:
docker compose run --rm --entrypoint sh videtrack -c \
  'cp /data/backups/pre-migrate-<time>.sqlite /data/database.sqlite'
VIDETRACK_VERSION=<previous version> docker compose up -d
```

Anything logged after the upgrade is lost with the snapshot, so take a fresh
[backup](administration.md#backup) first if the instance ran for a while.
