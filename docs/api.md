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

Reading: your profile, search, many titles by name at once with your state for each (`lookup`), one
title's details and history, your library (`history`, `ratings`, `favorites`, `progress`, each also
only what you rated at least so high), the next
episode of every show you watch (`up-next`), your lists, the release calendar (from a day, to a day or
for a number of `days`), every premiere you count down to, years ahead (`countdowns`), your statistics for a year, a range of
days or the whole history (`statistics`, each section of the statistics page on its own), what is
trending, popular and coming up in a type (`discover`), suggestions and notifications. Search and
the popular and coming-up lists of `discover` keep to your adult content setting (Settings →
Preferences), as the pages do; `lookup` names titles you already know, so it finds them whatever
that setting, as an import does.

With `write`: plays, ratings, status, progress, favorites, list membership (all of a title's lists
at once, one list at a time, or up to 25 titles onto one list in one call, in the order given), rewatch cycles, the
release bell (titles, people, companies, networks, collections), people and companies on lists,
and list management.

## Shape of the answers

- Answers are JSON in camelCase, always under `data` (a resource or a list of them), with `meta`
  where there is something to count or page. Request fields are snake_case.
- A title carries what you have done with it as its `state`.
- A write that changes a title also returns `states`: every state the write touched, keyed
  `type:ref`. Marking an episode watched returns the episode, its season and its show.
- A play carries `source`, where it came in from: `ui`, `api`, `import:<service>` or
  `scrobble:<server>`. Plays recorded before VideTrack kept it have `null`. A restored backup keeps
  each play's source; a play the backup has none for comes back as `import:videtrack`.
- Errors are always JSON: `{"message": …}`, plus `{"errors": {…}}` for a 422. A token without the
  needed ability gets a 403. A media type this instance does not serve (its keys are not
  configured) gets a 404.
- The rate limit is 240 requests per minute per token. `lookup` and adding titles to a list
  (`POST lists/{list}/items`) are also charged per title, since each title may be fetched from its
  catalogue: 120 titles a minute each, counted apart, so looking titles up and then adding them is not
  charged twice.
- Adding titles to a list answers each title once, in the order first given, with its `outcome`: `added`,
  `present` (on the list already; it keeps its place), `not_found` (its catalogue does not know the
  ref) or `unavailable` (its catalogue could not be asked; when it was down or busy, asking again later
  may do). `meta` counts each outcome and gives the list's `itemsCount`; `states` holds the titles from
  the call that are on the list.

## MCP server for AI assistants

The same token connects an AI assistant (Claude, or any client of the
[Model Context Protocol](https://modelcontextprotocol.io)) to your library at `/api/mcp`. Settings → API
shows the full address. The assistant can then do almost anything you do in VideTrack: say what you
watch next or what comes out this week, check which of twenty films you have seen, tick off an
episode, rate a film, drop a show, or build a list for you.

Each tool calls the API above, so it follows the same rules and records what it writes with the source
`api`. Its answers are compact: no pictures, a field that is false or empty is left out, and a write on one
title carries that title's state once, on the title, with `states` holding only the others it moved. A token
without `write` sees only the reading tools.

| Reading | |
|---|---|
| `search` | titles, people, companies or collections by name |
| `check_titles` | up to 25 titles by name and year at once, each with your state |
| `get_title`, `get_history` | one title in full; every time you watched it |
| `get_library` | your history, ratings, favorites or titles in progress, a page at a time, also only what you rated at least so high |
| `get_up_next`, `get_calendar` | the next episode of each show; releases day by day (a week by default) |
| `get_countdowns` | every premiere you count down to, soonest first, years ahead |
| `get_statistics` | your statistics for a year, a range or the whole history, a section at a time; two calls compare two periods |
| `get_trending` | what is trending, popular and coming up in a type, with your state for each |
| `get_lists`, `get_list` | your lists (the Backlog among them) and what is on one |
| `get_suggestions`, `get_notifications` | your suggestions; your notifications |

| Writing (`write`) | |
|---|---|
| `mark_watched`, `unmark_watched`, `delete_history_entry` | log a viewing (or the next episode of a show); take it back |
| `rate`, `remove_rating`, `set_favorite`, `set_status`, `log_progress` | rating, favorite, dropped or on hold, pages or hours |
| `follow_releases`, `follow_creator` | the release bell on a title, a person, a company or a collection |
| `start_rewatch`, `cancel_rewatch` | watch a show again from the start |
| `create_list`, `update_list`, `delete_list`, `remove_from_list` | your lists |
| `add_to_list` | up to 25 titles onto a list in one call, in the order given |
| `reorder_list_items`, `reorder_lists`, `duplicate_list` | the order of what is on a list, the order of your lists, a copy of a list |
| `mark_notifications_read` | one notification, or all |

A tool counts against the API's rate limit as the calls it makes (most make one; `update_list` makes
two). `search`, `get_title` and `get_trending` also count against the 120 catalogue lookups a minute your
account has; `check_titles` and `add_to_list` each have 120 titles a minute of their own.

Claude Code:

```sh
claude mcp add --transport http videtrack https://your-host/api/mcp \
  --header "Authorization: Bearer <token>"
```

A client that can only start local servers (Claude Desktop's config file, for one) reaches it through
[`mcp-remote`](https://www.npmjs.com/package/mcp-remote):

```json
{
  "mcpServers": {
    "videtrack": {
      "command": "npx",
      "args": ["mcp-remote", "https://your-host/api/mcp", "--header", "Authorization:${AUTH_HEADER}"],
      "env": { "AUTH_HEADER": "Bearer <token>" }
    }
  }
}
```

For an instance on the same computer, write `http://127.0.0.1:8080/api/mcp`, not `localhost`: Docker
publishes the port on IPv4, and some clients try `localhost` over IPv6 first and fail to connect. Over
plain `http://` to any other address, `mcp-remote` also needs `--allow-http`.

An operator who does not want it sets `MCP_ENABLED=false`; the API stays open.

## Full reference

[`openapi.json`](api/openapi.json) (OpenAPI 3.1). Open it in any OpenAPI tool (Swagger Editor,
Postman, Bruno, Insomnia) to browse every endpoint; to try one, set the server to your instance's
address followed by `/api/v1`.

# Webhooks

The other way round: VideTrack tells an address of yours (n8n, Home Assistant, a script) what
happens in your library, seconds after it happens. Add one in **Settings → Webhooks**, pick the events
it hears about, and press **Generate** for its signing secret, which your receiver needs to check
messages (it is shown only until you save). An address in your home network works too; one that leads
to the server VideTrack runs on (`localhost`, another container beside it) takes an administrator
account, or its host listed by the operator in `INTEGRATION_PRIVATE_HOSTS`.

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
