Shapes from MDBList's own API reference (the OpenAPI schema served at
api.mdblist.com/schema/, read 2026-11-21): `GET /sync/watched?plays=all`,
`/sync/ratings`, `/sync/dropped`, `/watchlist/items`, `/lists/user` and
`/lists/{id}/items`, with the ids of the reference's own examples. The
reference spells out the watched entries, the episode rating and the list
items; a movie, show and season rating and a dropped show are written in
the shape of the other sync entries, which it leaves open. The key rides
in the `apikey` query, as the schema's `apiKey` scheme says; `/user` names
its account. Dates are made up. No
response here was captured from the live API.
