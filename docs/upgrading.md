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

Images are tagged `X.Y.Z`, `X.Y` and `latest` for releases. `edge` follows the main branch and is
not a release: do not run it on data you care about.

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
