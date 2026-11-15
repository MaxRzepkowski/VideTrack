import { formatNumber } from '@/lib/format';
import { pageLocale } from '@/lib/pageContext';
import { ratingPercent } from '@/lib/ratings';
import type { LanguageSupport, MultiplayerMode, MultiplayerSupport, ProviderRating } from '@/types/generated';
import { trans, transChoice } from 'laravel-vue-i18n';

// Chinese is told apart by script, not by country: "Traditional", not "Taiwan".
const SCRIPT_OF: Record<string, string> = { 'zh-CN': 'zh-Hans', 'zh-TW': 'zh-Hant', 'zh-HK': 'zh-Hant' };

function displayName(tag: string): string {
    try {
        return new Intl.DisplayNames([pageLocale()], { type: 'language' }).of(tag) ?? tag;
    } catch {
        return tag;
    }
}

/** One row of a game's language table: the language named for the reader, and how the game speaks it. */
export type LanguageRow = { locale: string; name: string; audio: boolean; subtitles: boolean; interface: boolean };

/**
 * The languages a game speaks, named in the reader's language: theirs first,
 * the rest alphabetically. The variant shows only where the game has the
 * language twice (European and Mexican Spanish), the script for Chinese.
 */
export function languageRows(languages: LanguageSupport[]): LanguageRow[] {
    const base = (locale: string) => locale.split('-')[0] ?? locale;
    const bases = languages.map((language) => base(language.locale));
    const twice = new Set(bases.filter((b, i) => bases.indexOf(b) !== i));
    const reader = base(pageLocale());

    const isReader = (row: LanguageRow) => base(row.locale) === reader;

    return languages
        .map((language): LanguageRow => {
            const tag = SCRIPT_OF[language.locale] ?? (twice.has(base(language.locale)) ? language.locale : base(language.locale));

            return { ...language, name: displayName(tag) };
        })
        .sort((a, b) => Number(isReader(b)) - Number(isReader(a)) || a.name.localeCompare(b.name, pageLocale()));
}

const MODE_LABELS: Record<MultiplayerMode, () => string> = {
    online_coop: () => trans('media.multiplayer_online_coop'),
    local_coop: () => trans('media.multiplayer_local_coop'),
    lan_coop: () => trans('media.multiplayer_lan_coop'),
    campaign_coop: () => trans('media.multiplayer_campaign_coop'),
    drop_in: () => trans('media.multiplayer_drop_in'),
    split_screen: () => trans('media.multiplayer_split_screen'),
    online: () => trans('media.multiplayer_online'),
    local: () => trans('media.multiplayer_local'),
};

/** A way of playing together, with the most players where the provider says ("Online co-op (up to 4 players)"). */
export function multiplayerLabel(support: MultiplayerSupport): string {
    const mode = MODE_LABELS[support.mode]();

    return support.players === null ? mode : transChoice('media.multiplayer_up_to', support.players, { mode, count: String(support.players) });
}

/** A score with how many gave it ("88% – 12 reviews"); the count is said in the audience's own word. */
export function providerRatingLabel(
    rating: ProviderRating | null | undefined,
    countKey: 'media.count_critic_reviews' | 'media.count_player_ratings',
): string | null {
    if (!rating) {
        return null;
    }

    return `${ratingPercent(rating.score)} – ${transChoice(countKey, rating.count, { amount: formatNumber(rating.count) })}`;
}
