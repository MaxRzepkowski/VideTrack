/**
 * A browser told apart by its own description, for the list of devices push
 * reaches: its name and the system it runs on, each null when unknown. A
 * browser built on another (Brave on Chrome) names the one it is built on.
 */
export interface BrowserDescription {
    browser: string | null;
    system: string | null;
}

// Each one's mark, in the order they must be tried: Edge and Opera also say "Chrome", Chrome also says "Safari".
const BROWSERS: [RegExp, string][] = [
    [/Edg(A|iOS)?\//, 'Edge'],
    [/OPR\//, 'Opera'],
    [/SamsungBrowser\//, 'Samsung Internet'],
    [/Firefox\/|FxiOS\//, 'Firefox'],
    [/Chrome\/|CriOS\//, 'Chrome'],
    [/Version\/.+Safari\//, 'Safari'],
];

// Android also says "Linux", an iPhone "like Mac OS X".
const SYSTEMS: [RegExp, string][] = [
    [/Android/, 'Android'],
    [/iPhone|iPod/, 'iOS'],
    [/iPad/, 'iPadOS'],
    [/Windows/, 'Windows'],
    [/CrOS/, 'ChromeOS'],
    [/Macintosh|Mac OS X/, 'macOS'],
    [/Linux/, 'Linux'],
];

export function describeBrowser(userAgent: string | null): BrowserDescription {
    const name = (marks: [RegExp, string][]): string | null =>
        userAgent === null ? null : (marks.find(([pattern]) => pattern.test(userAgent))?.[1] ?? null);

    return { browser: name(BROWSERS), system: name(SYSTEMS) };
}
