import { trans } from 'laravel-vue-i18n';

import type { TitleStatus } from '@/types/generated';

export type { TitleStatus };

// Thunks, so every `trans()` stays a literal call the key checks can see.
const LABELS: Record<TitleStatus, () => string> = {
    rumored: () => trans('status.rumored'),
    planned: () => trans('status.planned'),
    in_production: () => trans('status.in_production'),
    post_production: () => trans('status.post_production'),
    pilot: () => trans('status.pilot'),
    alpha: () => trans('status.alpha'),
    beta: () => trans('status.beta'),
    early_access: () => trans('status.early_access'),
    not_yet_published: () => trans('status.not_yet_published'),
    released: () => trans('status.released'),
    returning_series: () => trans('status.returning_series'),
    publishing: () => trans('status.publishing'),
    on_hiatus: () => trans('status.on_hiatus'),
    ended: () => trans('status.ended'),
    finished: () => trans('status.finished'),
    canceled: () => trans('status.canceled'),
    discontinued: () => trans('status.discontinued'),
    offline: () => trans('status.offline'),
    delisted: () => trans('status.delisted'),
};

/**
 * The status in the viewer's language. Mirrors TitleStatus::label() in PHP,
 * which writes the same text into notifications sent outside the page.
 */
export function titleStatusLabel(status: TitleStatus | null): string | null {
    return status === null ? null : LABELS[status]();
}
