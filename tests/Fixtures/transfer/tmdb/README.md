Shapes from TMDB's API v3 reference (developer.themoviedb.org, read
2026-11-21): authentication/token/new, authentication/session/new, account,
account/{id}/watchlist|favorite|rated/{movies,tv}, rated/tv/episodes,
account/{id}/lists and list/{id}, trimmed to the fields an import reads,
with the reference's own example titles; error bodies follow the reference's
Errors table (status_code 7, 34, 39). The `page` and `total_pages` of
list.json are assumed: the reference's list example shows neither and reads
as one page without them. No response here was captured from the live API.
