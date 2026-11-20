import {
    siAppstore,
    siBluesky,
    siDiscord,
    siEpicgames,
    siFacebook,
    siFandom,
    siGamejolt,
    siGithub,
    siGitlab,
    siGogdotcom,
    siGoodreads,
    siGoogle,
    siGoogleplay,
    siIgdb,
    siImdb,
    siInstagram,
    siItchdotio,
    siMeta,
    siMyanimelist,
    siOpenid,
    siPlaystation,
    siReddit,
    siSteam,
    siThemoviedatabase,
    siTiktok,
    siTwitch,
    siWikidata,
    siWikipedia,
    siX,
    siYoutube,
} from 'simple-icons';

/**
 * Brand marks drawn in the colour of the text around them, one map for the
 * app: the services a title page links out to, the stores a game is sold in
 * and the ways to sign in, by the code the backend uses. A service's colour
 * logo, shown on its own (import, export, notifications), is `serviceLogos`.
 *
 * Official paths from simple-icons (Xbox and Nintendo below). A product with no glyph (Nuvio,
 * Harbor) falls back to a generic mark at the call site — inventing one
 * would be worse than a neutral square. Brand names are not translated.
 */
/*
 * Microsoft and Nintendo took their marks out of simple-icons; these two are
 * from Material Design Icons (Pictogrammers Free License), drawn on the same
 * 24×24 grid.
 */
const XBOX_PATH =
    'M6.43,3.72C6.5,3.66 6.57,3.6 6.62,3.56C8.18,2.55 10,2 12,2C13.88,2 15.64,2.5 17.14,3.42C17.25,3.5 17.54,3.69 17.7,3.88C16.25,2.28 12,5.7 12,5.7C10.5,4.57 9.17,3.8 8.16,3.5C7.31,3.29 6.73,3.5 6.46,3.7M19.34,5.21C19.29,5.16 19.24,5.11 19.2,5.06C18.84,4.66 18.38,4.56 18,4.59C17.61,4.71 15.9,5.32 13.8,7.31C13.8,7.31 16.17,9.61 17.62,11.96C19.07,14.31 19.93,16.16 19.4,18.73C21,16.95 22,14.59 22,12C22,9.38 21,7 19.34,5.21M15.73,12.96C15.08,12.24 14.13,11.21 12.86,9.95C12.59,9.68 12.3,9.4 12,9.1C12,9.1 11.53,9.56 10.93,10.17C10.16,10.94 9.17,11.95 8.61,12.54C7.63,13.59 4.81,16.89 4.65,18.74C4.65,18.74 4,17.28 5.4,13.89C6.3,11.68 9,8.36 10.15,7.28C10.15,7.28 9.12,6.14 7.82,5.35L7.77,5.32C7.14,4.95 6.46,4.66 5.8,4.62C5.13,4.67 4.71,5.16 4.71,5.16C3.03,6.95 2,9.35 2,12A10,10 0 0,0 12,22C14.93,22 17.57,20.74 19.4,18.73C19.4,18.73 19.19,17.4 17.84,15.5C17.53,15.07 16.37,13.69 15.73,12.96Z';
const NINTENDO_PATH =
    'M10.04,20.4H7.12C6.19,20.4 5.3,20 4.64,19.36C4,18.7 3.6,17.81 3.6,16.88V7.12C3.6,6.19 4,5.3 4.64,4.64C5.3,4 6.19,3.62 7.12,3.62H10.04V20.4M7.12,2A5.12,5.12 0 0,0 2,7.12V16.88C2,19.71 4.29,22 7.12,22H11.65V2H7.12M5.11,8C5.11,9.04 5.95,9.88 7,9.88C8.03,9.88 8.87,9.04 8.87,8C8.87,6.96 8.03,6.12 7,6.12C5.95,6.12 5.11,6.96 5.11,8M17.61,11C18.72,11 19.62,11.89 19.62,13C19.62,14.12 18.72,15 17.61,15C16.5,15 15.58,14.12 15.58,13C15.58,11.89 16.5,11 17.61,11M16.88,22A5.12,5.12 0 0,0 22,16.88V7.12C22,4.29 19.71,2 16.88,2H13.65V22H16.88Z';

const BRANDS: Record<string, { path: string; label: string }> = {
    tmdb: { path: siThemoviedatabase.path, label: 'TMDB' },
    imdb: { path: siImdb.path, label: 'IMDb' },
    instagram: { path: siInstagram.path, label: 'Instagram' },
    facebook: { path: siFacebook.path, label: 'Facebook' },
    x: { path: siX.path, label: 'X' },
    tiktok: { path: siTiktok.path, label: 'TikTok' },
    youtube: { path: siYoutube.path, label: 'YouTube' },
    wikidata: { path: siWikidata.path, label: 'Wikidata' },
    wikipedia: { path: siWikipedia.path, label: 'Wikipedia' },
    steam: { path: siSteam.path, label: 'Steam' },
    gog: { path: siGogdotcom.path, label: 'GOG' },
    twitch: { path: siTwitch.path, label: 'Twitch' },
    reddit: { path: siReddit.path, label: 'Reddit' },
    itch: { path: siItchdotio.path, label: 'itch.io' },
    epicgames: { path: siEpicgames.path, label: 'Epic Games' },
    discord: { path: siDiscord.path, label: 'Discord' },
    bluesky: { path: siBluesky.path, label: 'Bluesky' },
    playstation: { path: siPlaystation.path, label: 'PlayStation' },
    meta: { path: siMeta.path, label: 'Meta' },
    gamejolt: { path: siGamejolt.path, label: 'Game Jolt' },
    fandom: { path: siFandom.path, label: 'Fandom' },
    appstore: { path: siAppstore.path, label: 'App Store' },
    googleplay: { path: siGoogleplay.path, label: 'Google Play' },
    xbox: { path: XBOX_PATH, label: 'Xbox' },
    gamepass: { path: XBOX_PATH, label: 'Game Pass' },
    nintendo: { path: NINTENDO_PATH, label: 'Nintendo' },
    mal: { path: siMyanimelist.path, label: 'MyAnimeList' },
    goodreads: { path: siGoodreads.path, label: 'Goodreads' },
    igdb: { path: siIgdb.path, label: 'IGDB' },
    google: { path: siGoogle.path, label: 'Google' },
    github: { path: siGithub.path, label: 'GitHub' },
    gitlab: { path: siGitlab.path, label: 'GitLab' },
    openid: { path: siOpenid.path, label: 'OpenID' },
};

export function glyphFor(code: string): string | null {
    return BRANDS[code]?.path ?? null;
}

/** A service's mark and its name; null where it has no mark of its own. */
export function brandFor(code: string): { path: string; label: string } | null {
    return BRANDS[code] ?? null;
}
