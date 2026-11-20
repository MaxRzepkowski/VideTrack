/*
 * The mark of each outside service the app names (a notification channel's
 * kind, an import source, an export format, a media server), by its code: a
 * logo file from public/images/logos named in serviceLogos.json (with its
 * proportions and where it came from; scripts/service-logos.php measures a
 * file added by hand), or a plain icon for what has no brand of its own.
 * Codes of two registries may share a file: a brand is one drawing.
 */
import manifest from '@/lib/serviceLogos.json';
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

export interface ServiceLogo {
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

/** A service's logo file and its proportions; null for a code without one. */
export function serviceLogo(code: string): ServiceLogo | null {
    const entry = LOGOS[code];

    return entry === undefined ? null : { url: `/images/logos/${entry.file}`, width: entry.width, height: entry.height };
}

/** The plain icon of a code that has no brand; null for a branded one. */
export function serviceIcon(code: string): LucideIcon | null {
    return PLAIN[code] ?? null;
}
