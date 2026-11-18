# Changelog

Written by release-please from the pull request titles on `main`. The first release is summarized by hand.

## [0.2.0](https://github.com/MaxRzepkowski/VideTrack/compare/v0.1.0...v0.2.0) (2026-11-18)


### ⚠ BREAKING CHANGES

* stop sending the visitor's browser header to providers ([#49](https://github.com/MaxRzepkowski/VideTrack/issues/49))

### Features

* add webhooks to settings ([5cdbff5](https://github.com/MaxRzepkowski/VideTrack/commit/5cdbff5f238c03e17bcbb9445c6f02b8783a1787))
* browse manga by MyAnimeList's rankings on the discover page ([2c658c0](https://github.com/MaxRzepkowski/VideTrack/commit/2c658c0511ec62efc4ad822857f26ef540ca4002))
* compare your ratings with everyone else's in statistics ([f914194](https://github.com/MaxRzepkowski/VideTrack/commit/f91419467654ac4445d5092188c84db5920a6dea))
* export anime and manga lists in MyAnimeList's XML format ([58af4c2](https://github.com/MaxRzepkowski/VideTrack/commit/58af4c20325f4b15628ab52dd4f2ffb02d7bb6ba))
* fill out the manga page with MyAnimeList's details ([fd93879](https://github.com/MaxRzepkowski/VideTrack/commit/fd93879876e00347b586a0fb98b55192531d64ed))
* fit statistics to phone screens and screen readers ([2332b82](https://github.com/MaxRzepkowski/VideTrack/commit/2332b8214075ccb77a8dfbd35f332af7272f981b))
* import a MyAnimeList XML export file ([209c5c5](https://github.com/MaxRzepkowski/VideTrack/commit/209c5c56b46d47d619a88177cad357eee81f077f))
* import from Letterboxd, TV Time, StoryGraph, Backloggd and Jellyfin Playback Reporting ([cde2e49](https://github.com/MaxRzepkowski/VideTrack/commit/cde2e4957874b778f233ef4877ae66bb3fa89dfb))
* import from Netflix, Playnite, GOG Galaxy, Calibre and five more ([055c415](https://github.com/MaxRzepkowski/VideTrack/commit/055c4151284c78e99f27ef157a4335fce031e327))
* link statistics from the dashboard and your profile ([d7afb4d](https://github.com/MaxRzepkowski/VideTrack/commit/d7afb4ded5d58479b3993956c441c9c11489db60))
* log in with your own OpenID Connect server, Google, GitHub or GitLab ([f374558](https://github.com/MaxRzepkowski/VideTrack/commit/f37455830c3d601e9ee03f02f919b76854824bf0))
* narrow the calendar feed, follow it as RSS and replace its address ([4337d09](https://github.com/MaxRzepkowski/VideTrack/commit/4337d09de10fe6060cddfd405d32a1bb27fd66d6))
* notification channels for Discord, e-mail and Apprise services ([200f78f](https://github.com/MaxRzepkowski/VideTrack/commit/200f78f9f96a7190b21dcfeec34fb38320b3a083))
* one notification per new title and per show's episodes on a day ([6b83cba](https://github.com/MaxRzepkowski/VideTrack/commit/6b83cba19b657a201b2a00e7d92a1c3af2f1cdde))
* pick the statistics period and type from a sticky toolbar ([e8286bf](https://github.com/MaxRzepkowski/VideTrack/commit/e8286bf07fff78d3fd7d51e00084e7be39462990))
* send library changes and notifications to your webhooks ([6b17ba6](https://github.com/MaxRzepkowski/VideTrack/commit/6b17ba68b5132b24a764f7bddcc9baa1700f0a66))
* show a manga's sequels and spin-offs from MyAnimeList ([cc8e17e](https://github.com/MaxRzepkowski/VideTrack/commit/cc8e17e8aedbcc88ef56a79ec76b417264df3d02))
* show manga rankings as tiles and recommendations higher up ([82a0a17](https://github.com/MaxRzepkowski/VideTrack/commit/82a0a176284a8b569a8aabd5b34342957abe697f))
* show the cast, directors, writers and authors behind your titles ([bf65e99](https://github.com/MaxRzepkowski/VideTrack/commit/bf65e9908e585d8716c6cf982afaed35cbf45ece))
* show title origins and their networks or studios in statistics ([2d26ac9](https://github.com/MaxRzepkowski/VideTrack/commit/2d26ac9180b6d3dbeff09fc48efa5651604ed2ca))
* show your longest streak, busiest day and biggest marathon ([94512f2](https://github.com/MaxRzepkowski/VideTrack/commit/94512f2380171ce6d04ac140f36a9cd41c2a115c))
* statistics page with time spent, activity, genres and reading time ([f0d1ee0](https://github.com/MaxRzepkowski/VideTrack/commit/f0d1ee0c35b18eecf6669b69a0d2c1e3a0d6887a))


### Bug Fixes

* call a season premiere the episode TMDB marks as opening it ([0e86780](https://github.com/MaxRzepkowski/VideTrack/commit/0e867804d4f5c81f9ed3de1ebb5b748f40ef9947))
* hide only adult manga, not suggestive ones, from search ([dbbf471](https://github.com/MaxRzepkowski/VideTrack/commit/dbbf47173cd02aae388aec6a80a392a555eb59bd))
* keep a separator from starting a wrapped line of facts ([b4077ee](https://github.com/MaxRzepkowski/VideTrack/commit/b4077ee833d3184780f046504f1096541f9ed692))
* show a manga start known only to the year as the year ([8d1aa61](https://github.com/MaxRzepkowski/VideTrack/commit/8d1aa61620ce779b7973a8621b5942fb1d921c00))
* show manga under their English title ([#46](https://github.com/MaxRzepkowski/VideTrack/issues/46)) ([e48c356](https://github.com/MaxRzepkowski/VideTrack/commit/e48c356ecd3d9a435953b3c963c8da6090f324ba))
* show the header menu in a drawer on tablets ([d8b079c](https://github.com/MaxRzepkowski/VideTrack/commit/d8b079c76d19778deb1bf9f310ce31be292289ef))
* show the running version and a source link in the footer ([#51](https://github.com/MaxRzepkowski/VideTrack/issues/51)) ([2a15919](https://github.com/MaxRzepkowski/VideTrack/commit/2a15919cd26760e0cbb0ff93d6f927f24939a703))
* stop sending the visitor's browser header to providers ([#49](https://github.com/MaxRzepkowski/VideTrack/issues/49)) ([fac48c5](https://github.com/MaxRzepkowski/VideTrack/commit/fac48c51644404fb4a59f3dbe4ade2f07abc36ca))


### Performance

* keep worked-out statistics until your library changes ([0c8c9c3](https://github.com/MaxRzepkowski/VideTrack/commit/0c8c9c39346d8e705dde0ddfbffa815afb4b6ef5))

## 0.1.0 (2026-11-15)

The first public release of VideTrack, a self-hosted tracker for movies, TV shows, games, books, manga and comics.

### What it does

- Keeps movies, shows, games, books, manga and comics in one library, with the next episode of every show you watch and whatever you are in the middle of.
- Follows upcoming releases in a calendar and countdowns, which you can also subscribe to from your own calendar app.
- Tells you about new releases of the titles, people and studios you follow, in the app or on Discord.
- Ratings, favorites, a backlog and your own lists, with a full history including rewatches.
- Suggestions based on what you finished, on a page of their own.
- Imports from Trakt, Simkl, IMDb, Goodreads, MyAnimeList, AniList, Kitsu, Steam and more; logs what you watch from Plex, Jellyfin, Emby or Tautulli; exports a full backup, CSV, or files for IMDb and Letterboxd.
- An account for everyone at home, in English or Polish, and an API for your own scripts.

### Install

One Docker container with one SQLite file: follow [Install](https://github.com/MaxRzepkowski/VideTrack#install) in the README. Images for amd64 and arm64: `ghcr.io/maxrzepkowski/videtrack:0.1.0` (also `0.1` and `latest`).
