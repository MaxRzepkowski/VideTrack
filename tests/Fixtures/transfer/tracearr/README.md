Shapes built from Tracearr's own source, not captured live
(connorgallopo/Tracearr@ef0fb45, apps/server/src/routes/publicV2):
`mapHistoryRow` in shared.ts for `GET /history` (codec and transcode
fields trimmed), `GET /media/{ref}` in media.ts, `correlationBlock` in
users.ts for `GET /users`, and the `{data, meta: {nextCursor, pageSize}}`
cursor envelope.
