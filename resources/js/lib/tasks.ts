import { formatDateTime, formatTime, localDateKey, todayDateKey } from '@/lib/format';
import type { CheckLevel, TaskRunStatus, WordedLine } from '@/types/generated';
import { CircleCheck, CircleX, Clock, LoaderCircle, TriangleAlert, type LucideIcon } from '@lucide/vue';
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

/** @view How a line of a check reads: its icon and the colour of both. */
export interface LevelLook {
    icon: LucideIcon;
    tone: string;
}

/** A check's line in the run's colours: well as a success, passing as on hold, the admin's to fix as a failure. */
export function levelLook(level: CheckLevel): LevelLook {
    switch (level) {
        case 'ok':
            return { icon: CircleCheck, tone: 'text-success' };
        case 'warning':
            return { icon: TriangleAlert, tone: 'text-status-onhold' };
        case 'problem':
            return { icon: CircleX, tone: 'text-danger' };
    }
}

/**
 * A moment as every time on these pages is written: in the viewer's timezone
 * and time format, by the hour alone when it is today.
 */
export function writtenMoment(moment: string): string {
    return (localDateKey(moment) === todayDateKey() ? formatTime(moment) : formatDateTime(moment)) ?? '';
}

/** A sentence the server worded, its moments written here. */
export function wordedText(line: WordedLine): string {
    return Object.entries(line.moments).reduce((text, [name, moment]) => text.replaceAll(`:${name}`, writtenMoment(moment)), line.text);
}
