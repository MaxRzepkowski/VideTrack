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

[`openapi.json`](api/openapi.json) (OpenAPI 3.1). Open it in any OpenAPI tool (Swagger Editor,
Postman, Bruno, Insomnia) to browse every endpoint; to try one, set the server to your instance's
address followed by `/api/v1`.

# Webhooks

The other way round: VideTrack tells an address of yours (n8n, Home Assistant, a script) what
happens in your library, seconds after it happens. Add one in **Settings → Webhooks**, pick the events
it hears about, and press **Generate** for its signing secret, which your receiver needs to check
messages (it is shown only until you save). An address on your own network works too.

Each message is a `POST` of JSON, signed as [Standard Webhooks](https://www.standardwebhooks.com)
defines it:

```
POST <your address>
content-type: application/json
webhook-id: 019a7c3e-…
webhook-timestamp: 1763500443
webhook-signature: v1,…

{"type":"rating.changed","timestamp":"2026-11-18T21:14:03+00:00","source":"ui","data":{…}}
```

- `type` says what happened, `timestamp` when (for a change to your library, when VideTrack made the
  message, normally seconds after; a play's own time is its `watchedAt`), `data` what changed: titles,
  plays and notifications in the same shapes the API answers with.
- `source` says where a change to your library came from: `ui`, `api`, or `scrobble:<server>`.
  An import never sends anything.
- `webhook-id` stays the same when a message is tried again, so a receiver can drop a repeat. For a
  notification it is the notification's own id in the API.

## Events

| Group                 | `type`                                 | `data`                                                                                    |
| --------------------- | -------------------------------------- | ----------------------------------------------------------------------------------------- |
| Watching and progress | `play.recorded`                        | `media` (what was marked), `plays` (each with its `media`); one message for a whole batch |
|                       | `play.removed`                         | `media`, `playIds`                                                                        |
|                       | `progress.logged`                      | `media`, `added` (a session) and `set` (a correction), one of them null, `progress`       |
|                       | `rewatch.started`                      | `media`, `cycle`                                                                          |
| Ratings               | `rating.changed`                       | `media`, `rating` (1–10), `previous`                                                      |
|                       | `rating.removed`                       | `media`                                                                                   |
| Favorites             | `favorite.changed`                     | `media`, `favorite`                                                                       |
| Status                | `status.changed`                       | `media`, `status`, `previous`                                                             |
| Lists                 | `list.item_added`, `list.item_removed` | `list` (`id`, `title`), `media`, `person`, `company`: one of the last three is filled     |
| Notifications         | `notification.created`                 | `notification`, as `GET /api/v1/notifications` lists it                                   |
|                       | `webhook.test`                         | the **Send a test message** button                                                        |

## Checking the signature

The signature is an HMAC-SHA256, in base64, of `<webhook-id>.<webhook-timestamp>.<raw body>`, keyed
with the secret's base64 part after `whsec_`. The Standard Webhooks libraries do it for you:

```js
import { Webhook } from 'standardwebhooks';

// Throws unless the message came from your instance, unchanged, within the last few minutes.
const event = new Webhook(secret).verify(rawBody, headers);
```

```python
from standardwebhooks.webhooks import Webhook

event = Webhook(secret).verify(raw_body, headers)
```

Without a library, the check in plain Node.js (in an n8n Code node, allow the module with
`NODE_FUNCTION_ALLOW_BUILTIN=crypto`). It needs the body exactly as it arrived, not parsed and
written out again, and turns away a message more than five minutes old, which is what stops a
recorded one being played back:

```js
const crypto = require('crypto');

function fromVideTrack(secret, headers, rawBody) {
    const sentAt = Number(headers['webhook-timestamp']);
    if (!Number.isFinite(sentAt) || Math.abs(Date.now() / 1000 - sentAt) > 5 * 60) return false;

    const key = Buffer.from(secret.slice('whsec_'.length), 'base64');
    const signed = `${headers['webhook-id']}.${sentAt}.${rawBody}`;
    const expected = Buffer.from('v1,' + crypto.createHmac('sha256', key).update(signed).digest('base64'));

    return headers['webhook-signature']
        .split(' ')
        .some((candidate) => candidate.length === expected.length && crypto.timingSafeEqual(Buffer.from(candidate), expected));
}
```

**Home Assistant:** an automation with a webhook trigger, its address
`http://<home-assistant>:8123/api/webhook/<webhook_id>`. Home Assistant does not check signatures;
`trigger.json` is the message:

```yaml
triggers:
    - trigger: webhook
      webhook_id: videtrack-library
      allowed_methods: [POST]
conditions:
    - condition: template
      value_template: "{{ trigger.json.type == 'play.recorded' }}"
actions:
    - action: light.turn_on
      target: { entity_id: light.living_room }
      data: { brightness_pct: 80 }
```

## Delivery

- A title or a list deleted before the message was made goes as `null`.
- Any `2xx` answer counts as delivered. A redirect is not followed: update the address instead.
- A receiver that is down (`5xx`, no connection) is tried again after a minute and after five; one
  that says it is busy (`429`) a few seconds later. Any other `4xx` is not tried again.
- A `410 Gone` switches the webhook off. Every other failure only shows on the settings page,
  beside **Send a test message**.
