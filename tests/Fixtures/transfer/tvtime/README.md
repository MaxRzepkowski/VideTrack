A TV Time GDPR data package, shaped after the anonymized captures of real
packages in trakt/trakt-web (projects/client/src/lib/sections/settings/import/
parsers/__fixtures__/tvtime) and the headers its parser tests pin: the v2
tracking table (`key`, `s_id`/`ep_id` TVDB ids, `s_no`/`ep_no`, and a variant
filling only `season_number`/`episode_number`), the older one (`type-uuid-n`,
films by `alpha_range_key` slug and `watch-date-<epoch>`), `followed_tv_show.csv`
and `ratings-live-votes.csv`. Show names and TVDB ids are public catalogue
data; uuids and dates are made up, user ids are 0. The tracking files sit in a
folder because packages differ in where they put them.
