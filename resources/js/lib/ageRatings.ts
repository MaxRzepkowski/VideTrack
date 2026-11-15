/** A rating board's mark for one rating, as the page draws it. */
export type AgeRatingImage = { src: string; width: number; height: number };

/*
 * The boards' marks, kept with the app (`public/images/age-ratings`): IGDB's
 * own icon set, copied once so a page never reaches out for them. Keyed by the
 * board as the catalog names it and the rating as the board writes it. GRAC's
 * "18+", which IGDB draws no icon for, is the board's public-domain mark from
 * Wikimedia Commons.
 */
const IMAGES: Record<string, Record<string, AgeRatingImage>> = {
    PEGI: {
        '12': { src: '/images/age-ratings/pegi/12.png', width: 105, height: 128 },
        '16': { src: '/images/age-ratings/pegi/16.png', width: 105, height: 128 },
        '18': { src: '/images/age-ratings/pegi/18.png', width: 105, height: 128 },
        '3': { src: '/images/age-ratings/pegi/3.png', width: 105, height: 128 },
        '7': { src: '/images/age-ratings/pegi/7.png', width: 105, height: 128 },
    },
    ESRB: {
        AO: { src: '/images/age-ratings/esrb/ao.png', width: 85, height: 128 },
        E: { src: '/images/age-ratings/esrb/e.png', width: 85, height: 128 },
        'E10+': { src: '/images/age-ratings/esrb/e10.png', width: 85, height: 128 },
        EC: { src: '/images/age-ratings/esrb/ec.png', width: 60, height: 89 },
        M: { src: '/images/age-ratings/esrb/m.png', width: 86, height: 128 },
        RP: { src: '/images/age-ratings/esrb/rp.png', width: 85, height: 128 },
        T: { src: '/images/age-ratings/esrb/t.png', width: 85, height: 128 },
    },
    USK: {
        '0': { src: '/images/age-ratings/usk/0.png', width: 128, height: 128 },
        '12': { src: '/images/age-ratings/usk/12.png', width: 128, height: 128 },
        '16': { src: '/images/age-ratings/usk/16.png', width: 128, height: 128 },
        '18': { src: '/images/age-ratings/usk/18.png', width: 128, height: 128 },
        '6': { src: '/images/age-ratings/usk/6.png', width: 128, height: 128 },
    },
    CERO: {
        A: { src: '/images/age-ratings/cero/a.png', width: 104, height: 128 },
        B: { src: '/images/age-ratings/cero/b.png', width: 104, height: 128 },
        C: { src: '/images/age-ratings/cero/c.png', width: 104, height: 128 },
        D: { src: '/images/age-ratings/cero/d.png', width: 104, height: 128 },
        Z: { src: '/images/age-ratings/cero/z.png', width: 104, height: 128 },
    },
    GRAC: {
        '18+': { src: '/images/age-ratings/grac/18.svg', width: 110, height: 128 },
        '12+': { src: '/images/age-ratings/grac/12.png', width: 109, height: 128 },
        '15+': { src: '/images/age-ratings/grac/15.png', width: 109, height: 128 },
        '19+': { src: '/images/age-ratings/grac/19.png', width: 110, height: 128 },
        ALL: { src: '/images/age-ratings/grac/all.png', width: 109, height: 128 },
        TESTING: { src: '/images/age-ratings/grac/testing.png', width: 109, height: 128 },
    },
    ClassInd: {
        '10': { src: '/images/age-ratings/class_ind/10.png', width: 128, height: 128 },
        '12': { src: '/images/age-ratings/class_ind/12.png', width: 128, height: 128 },
        '14': { src: '/images/age-ratings/class_ind/14.png', width: 128, height: 128 },
        '16': { src: '/images/age-ratings/class_ind/16.png', width: 128, height: 128 },
        '18': { src: '/images/age-ratings/class_ind/18.png', width: 128, height: 128 },
        L: { src: '/images/age-ratings/class_ind/l.png', width: 128, height: 128 },
    },
    ACB: {
        G: { src: '/images/age-ratings/acb/g.png', width: 128, height: 128 },
        M: { src: '/images/age-ratings/acb/m.png', width: 128, height: 128 },
        'MA 15+': { src: '/images/age-ratings/acb/ma_15.png', width: 128, height: 128 },
        PG: { src: '/images/age-ratings/acb/pg.png', width: 128, height: 128 },
        'R 18+': { src: '/images/age-ratings/acb/r_18.png', width: 128, height: 128 },
        RC: { src: '/images/age-ratings/acb/rc.png', width: 128, height: 128 },
    },
};

/** The mark of a board's rating ("PEGI", "18"), null where none is kept. */
export function ageRatingImage(board: string, rating: string): AgeRatingImage | null {
    return IMAGES[board]?.[rating] ?? null;
}

/*
 * The marks some boards draw for the content behind a rating (PEGI's
 * "Violence", GRAC's "Language"), from the same icon set; keyed by the
 * descriptor as the board words it. ESRB and ClassInd write theirs as text.
 */
const DESCRIPTOR_IMAGES: Record<string, Record<string, AgeRatingImage>> = {
    PEGI: {
        'Bad Language': { src: '/images/age-ratings/pegi/descriptors/bad-language.png', width: 64, height: 64 },
        Discrimination: { src: '/images/age-ratings/pegi/descriptors/discrimination.png', width: 64, height: 64 },
        Drugs: { src: '/images/age-ratings/pegi/descriptors/drugs.png', width: 64, height: 64 },
        Fear: { src: '/images/age-ratings/pegi/descriptors/fear.png', width: 64, height: 64 },
        Gambling: { src: '/images/age-ratings/pegi/descriptors/gambling.png', width: 64, height: 64 },
        'In-Game Purchases': { src: '/images/age-ratings/pegi/descriptors/in-game-purchases.png', width: 64, height: 64 },
        'Online Gameplay': { src: '/images/age-ratings/pegi/descriptors/online-gameplay.png', width: 64, height: 64 },
        Sex: { src: '/images/age-ratings/pegi/descriptors/sex.png', width: 64, height: 64 },
        Violence: { src: '/images/age-ratings/pegi/descriptors/violence.png', width: 64, height: 64 },
    },
    CERO: {
        'Controlled substances': { src: '/images/age-ratings/cero/descriptors/controlled-substances.png', width: 64, height: 64 },
        Crime: { src: '/images/age-ratings/cero/descriptors/crime.png', width: 64, height: 64 },
        'Drinking/Smoking': { src: '/images/age-ratings/cero/descriptors/drinking-smoking.png', width: 64, height: 64 },
        Gambling: { src: '/images/age-ratings/cero/descriptors/gambling.png', width: 64, height: 64 },
        Horror: { src: '/images/age-ratings/cero/descriptors/horror.png', width: 64, height: 64 },
        'Languages and others': { src: '/images/age-ratings/cero/descriptors/languages-and-others.png', width: 64, height: 64 },
        Love: { src: '/images/age-ratings/cero/descriptors/love.png', width: 64, height: 64 },
        'Sexual Content': { src: '/images/age-ratings/cero/descriptors/sexual-content.png', width: 64, height: 64 },
        Violence: { src: '/images/age-ratings/cero/descriptors/violence.png', width: 64, height: 64 },
    },
    GRAC: {
        'Alcohol, Tobacco, Drug': { src: '/images/age-ratings/grac/descriptors/alcohol-tobacco-drug.png', width: 55, height: 64 },
        'Crime, Anti-Social': { src: '/images/age-ratings/grac/descriptors/crime-anti-social.png', width: 55, height: 64 },
        'Fear, Horror, Threatening': { src: '/images/age-ratings/grac/descriptors/fear-horror-threatening.png', width: 55, height: 64 },
        Gambling: { src: '/images/age-ratings/grac/descriptors/gambling.png', width: 55, height: 64 },
        Language: { src: '/images/age-ratings/grac/descriptors/language.png', width: 55, height: 64 },
        Sexuality: { src: '/images/age-ratings/grac/descriptors/sexuality.png', width: 55, height: 64 },
        Violence: { src: '/images/age-ratings/grac/descriptors/violence.png', width: 55, height: 64 },
    },
};

/** A board's mark for one content descriptor ("PEGI", "Violence"), null where it has none. */
export function descriptorImage(board: string, descriptor: string): AgeRatingImage | null {
    return DESCRIPTOR_IMAGES[board]?.[descriptor] ?? null;
}
