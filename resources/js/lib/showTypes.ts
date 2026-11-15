import { trans } from 'laravel-vue-i18n';

// TMDB's series types, one literal key each so every label is checked for a
// translation; a type TMDB adds later shows as it comes.
const SHOW_TYPE_LABELS: Record<string, () => string> = {
    Documentary: () => trans('common.documentary'),
    Miniseries: () => trans('common.miniseries'),
    News: () => trans('common.news'),
    Reality: () => trans('common.reality'),
    Scripted: () => trans('common.scripted'),
    'Talk Show': () => trans('common.talk_show'),
    Video: () => trans('common.video'),
};

/** A show's kind as the provider files it, in the reader's language; a kind it adds later shows as it comes. */
export function showTypeLabel(type: unknown): string | null {
    return typeof type === 'string' && type !== '' ? (SHOW_TYPE_LABELS[type]?.() ?? type) : null;
}
