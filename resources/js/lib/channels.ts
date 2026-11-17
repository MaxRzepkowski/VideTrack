/*
 * The notification channels page. A channel's plain settings come back to be
 * changed; a secret never does, so its field starts empty and an empty one
 * keeps what is saved.
 */
import { formatHourOfDay } from '@/lib/format';
import type { ChannelKindData, ChannelMode, CredentialField } from '@/types/generated';
import { trans } from 'laravel-vue-i18n';

export interface ModeChoice {
    value: ChannelMode;
    label: string;
}

/** When a channel hears about a notification, in the order the select lists them; the digest hour is the server's. */
export function channelModes(digestHour: number): ModeChoice[] {
    return [
        { value: 'digest', label: trans('settings.daily_digest_at_time', { time: formatHourOfDay(digestHour) }) },
        { value: 'instant', label: trans('settings.instantly_one_message_per_event') },
    ];
}

/** A channel's fields as an edit form starts them: the saved plain values, every secret empty. */
export function savedConfig(fields: CredentialField[], values: Record<string, string>): Record<string, string> {
    return Object.fromEntries(fields.map((field) => [field.key, field.secret ? '' : (values[field.key] ?? '')]));
}

/** A blank value for every field of a kind of channel. */
export function blankConfig(fields: CredentialField[]): Record<string, string> {
    return Object.fromEntries(fields.map((field) => [field.key, '']));
}

/** The validation error of one of a channel's fields, which the server names under `config`. */
export function configError(errors: Partial<Record<string, string>>, key: string): string | undefined {
    return errors[`config.${key}`];
}

/** The kinds whose name holds every word of the query, in their order; all of them for no query. */
export function kindsMatching(kinds: ChannelKindData[], query: string): ChannelKindData[] {
    const words = query
        .toLocaleLowerCase()
        .split(/\s+/)
        .filter((word) => word !== '');

    return kinds.filter((kind) => words.every((word) => kind.label.toLocaleLowerCase().includes(word)));
}
