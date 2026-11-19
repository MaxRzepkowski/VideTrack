/*
 * The mark of each service a notification channel can go to, by the kind's
 * code: a logo file from public/images/channels named in channelLogos.json
 * (with its proportions and where it came from; scripts/channel-logos.php
 * measures a file added by hand), or a plain icon for what has no brand of
 * its own.
 */
import manifest from '@/lib/channelLogos.json';
import {
    BellRing,
    Braces,
    ClipboardList,
    CodeXml,
    Hash,
    Mail,
    MessageSquareText,
    MonitorSmartphone,
    RadioTower,
    SquareTerminal,
    Tv,
    type LucideIcon,
} from '@lucide/vue';

interface LogoEntry {
    file: string;
    width: number;
    height: number;
    source: string;
    licence: string;
}

export interface ChannelLogo {
    url: string;
    width: number;
    height: number;
}

const LOGOS: Record<string, LogoEntry> = manifest;

/**
 * E-mail, a data format sent to your own address, a protocol, a program
 * without a logo, the browser itself: a plain icon, not a drawing passed off as a logo.
 */
const PLAIN: Record<string, LucideIcon> = {
    mail: Mail,
    mailtos: Mail,
    jsons: Braces,
    xmls: CodeXml,
    forms: ClipboardList,
    rsyslog: SquareTerminal,
    ircs: Hash,
    smpps: MessageSquareText,
    gorushs: BellRing,
    enigma2s: Tv,
    aprs: RadioTower,
    webpush: MonitorSmartphone,
};

/** A kind's logo file and its proportions; null for a kind without one. */
export function channelLogo(kind: string): ChannelLogo | null {
    const entry = LOGOS[kind];

    return entry === undefined ? null : { url: `/images/channels/${entry.file}`, width: entry.width, height: entry.height };
}

/** The plain icon of a kind that has no brand; null for a branded one. */
export function channelIcon(kind: string): LucideIcon | null {
    return PLAIN[kind] ?? null;
}
