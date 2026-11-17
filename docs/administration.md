# Administration

## User accounts

There is no admin panel. Users manage themselves, and the rare exceptions are artisan commands
run inside the container:

```sh
docker compose exec -u www-data videtrack php artisan vide:user:list
docker compose exec -u www-data videtrack php artisan vide:user:reset-password <user>
docker compose exec -u www-data videtrack php artisan vide:user:block <user> [--unblock]
docker compose exec -u www-data videtrack php artisan vide:user:delete <user>
docker compose exec -u www-data videtrack php artisan vide:user:promote <user> [--demote]
```

`<user>` is a username or an e-mail address. A password reset ends the account's sessions and
revokes its API tokens. `-u www-data` runs the command as the user the app runs as, so the files
it leaves in storage stay writable for it.

The status of background jobs (metadata refresh, cleanup and so on) is visible to every user
under **Settings → Tasks**. Administrators also see system jobs and the schedule there.

## Backup

The database is a single SQLite file on the `videtrack-data` volume, next to the app key
(`/data/app.key`). Keep the two together: stored tokens and the addresses of notification
channels cannot be read without the key.

The queue and the scheduler write to the database all the time, so a plain `cp` may capture a
half-finished write. Use `.backup`, which takes a consistent snapshot:

```sh
# Consistent backup of a running instance (no downtime):
docker compose exec videtrack \
  sqlite3 /data/database.sqlite ".backup '/data/backups/manual-$(date +%F).sqlite'"

# Copy the snapshot and the app key from the volume to the host:
docker compose cp videtrack:/data/backups/manual-$(date +%F).sqlite ./backup.sqlite
docker compose cp videtrack:/data/app.key ./app.key
```

The container also takes a snapshot before every migration on startup and keeps the last five
in `/data/backups/pre-migrate-*.sqlite`, so a failed migration always has a restore point.

Avatars and other user files live on a separate volume, `videtrack-storage`. Include it in your
backups:

```sh
docker run --rm -v videtrack_videtrack-storage:/s -v "$PWD":/b alpine \
  tar czf /b/storage.tar.gz -C /s .
```

## Restore

Stop the container without removing it, swap the database file and start again:

```sh
docker compose stop
docker compose cp ./backup.sqlite videtrack:/data/database.sqlite
docker compose start
```
