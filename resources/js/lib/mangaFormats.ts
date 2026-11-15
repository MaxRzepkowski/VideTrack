import { trans } from 'laravel-vue-i18n';

import type { MangaFormat } from '@/types/generated';

export type { MangaFormat };

const LABELS: Record<MangaFormat, () => string> = {
    manga: () => trans('media.manga_format_manga'),
    manhwa: () => trans('media.manga_format_manhwa'),
    manhua: () => trans('media.manga_format_manhua'),
    one_shot: () => trans('media.manga_format_one_shot'),
    doujinshi: () => trans('media.manga_format_doujinshi'),
    oel: () => trans('media.manga_format_oel'),
};

/**
 * The format chip in the reader's language. A stored label that is not a code
 * (the English label itself) is shown as it is.
 */
export function mangaFormatLabel(value: unknown): string | null {
    if (typeof value !== 'string' || value === '') {
        return null;
    }

    return value in LABELS ? LABELS[value as MangaFormat]() : value;
}
