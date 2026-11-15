# API

VideTrack serves a versioned REST API at `/api/v1`, meant for your own scripts and tools.
VideTrack's own pages use the very same API.

## Tokens

Create a token in **Settings → API**. It is shown once, always carries `read`, optionally
`write`, and may be given an expiry date. Send it as a bearer token:

```sh
curl -H "Authorization: Bearer <token>" https://your-host/api/v1/me
```

Tokens start with `vt_`, so secret scanners recognise one committed by mistake.

## Examples

```sh
# Mark a movie watched, right now:
curl -X POST -H "Authorization: Bearer <token>" -H "Content-Type: application/json" \
  -d '{"watched_at":"now"}' https://your-host/api/v1/media/movie/603/plays

# Rate an episode (refs may contain colons, they travel in the path):
curl -X PUT -H "Authorization: Bearer <token>" -H "Content-Type: application/json" \
  -d '{"rating":9}' https://your-host/api/v1/media/episode/1399:1:1/rating
```

## What it covers

Reading: your profile, search, one title's details and history, your library (`history`,
`ratings`, `favorites`, `progress`), your lists, the release calendar and notifications.

With `write`: plays, ratings, status, progress, favorites, list membership, rewatch cycles, the
release bell (titles, people, companies, networks, collections), people and companies on lists,
and list management.

## Shape of the answers

- Answers are JSON in camelCase, always under `data` (a resource or a list of them), with `meta`
  where there is something to count or page. Request fields are snake_case.
- A title carries what you have done with it as its `state`.
- A write that changes a title also returns `states`: every state the write touched, keyed
  `type:ref`. Marking an episode watched returns the episode, its season and its show.
- Errors are always JSON: `{"message": …}`, plus `{"errors": {…}}` for a 422. A token without the
  needed ability gets a 403. A media type this instance does not serve (its keys are not
  configured) gets a 404.
- The rate limit is 240 requests per minute per token.

## Full reference

[`openapi.json`](api/openapi.json) (OpenAPI 3.1). Anyone signed in gets an interactive version
at `/docs/api`.
