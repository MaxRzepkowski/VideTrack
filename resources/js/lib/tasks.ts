import type { TaskRunStatus } from '@/types/generated';
import { CircleCheck, CircleX, Clock, LoaderCircle, type LucideIcon } from '@lucide/vue';
import { trans } from 'laravel-vue-i18n';

/** @view How a run's ending reads: its word, its icon and their colour. */
export interface StatusLook {
    label: string;
    icon: LucideIcon;
    /** The colour of the icon and the word. */
    tone: string;
    spin?: boolean;
}

export function statusLook(status: TaskRunStatus): StatusLook {
    switch (status) {
        case 'pending':
            return { label: trans('tasks.pending'), icon: Clock, tone: 'text-ink-muted' };
        case 'started':
            return { label: trans('tasks.started'), icon: LoaderCircle, tone: 'text-status-progress', spin: true };
        case 'success':
            return { label: trans('tasks.success'), icon: CircleCheck, tone: 'text-success' };
        case 'failure':
            return { label: trans('tasks.failed'), icon: CircleX, tone: 'text-danger' };
    }
}

/** Queued or at work: the page asks again until it ends. */
export function isUnderWay(status: TaskRunStatus | null): boolean {
    return status === 'pending' || status === 'started';
}
