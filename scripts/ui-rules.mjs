// The UI bans (dots, dashes, small uppercase, colours by token…) checked over the front's
// source. Prints every violation as file:line and fails when any is not on
// the exception list below. Run: node scripts/ui-rules.mjs
import { parse } from '@vue/compiler-sfc';
import { readdirSync, readFileSync, statSync } from 'node:fs';
import { join, relative } from 'node:path';

const ROOT = new URL('..', import.meta.url).pathname;
const SOURCE = join(ROOT, 'resources/js');

/**
 * Known violations, each with the reason it is there: "rule|file". The
 * list only shrinks; new code never joins it.
 */
const EXCEPTIONS = new Set([]);

const PALETTE = 'slate|gray|zinc|neutral|stone|red|orange|amber|yellow|lime|green|emerald|teal|cyan|sky|blue|indigo|violet|purple|fuchsia|pink|rose';

const RULES = [
    {
        id: 'middle-dot',
        why: "'·' as a separator — use the hairline <span class=\"bg-line h-2 w-px\"> or '–' in a string",
        test: (line) => line.includes('·'),
    },
    {
        id: 'background-image',
        why: 'background-image cannot lazy-load — an absolutely positioned <img> with object-cover instead',
        test: (line) => /bg-\[url\(|backgroundImage|background-image\s*:/.test(line),
    },
    {
        id: 'tiny-uppercase',
        why: 'small uppercase labels — text-sm minimum, normal case',
        test: (line) => /\buppercase\b/.test(line) && /\btext-(xs|\[(9|10|11|12)px\])/.test(line),
    },
    {
        // A small filled circle as a type or status marker. Allowed in two
        // places, where a dot is the established sign: the unread mark of a notification (on its row,
        // and on the bell as `UNREAD_MARK`), and the red "live" mark of an event on the air (`bg-live`).
        id: 'coloured-dot',
        why: 'a coloured dot as a marker — a type takes its glyph from mediaTypeMeta(), a separator is the hairline',
        test: (line) =>
            /\brounded-full\b/.test(line) &&
            (/\bsize-(1|1\.5|2|2\.5)\b/.test(line) || (/\bh-(1|1\.5|2|2\.5)\b/.test(line) && /\bw-(1|1\.5|2|2\.5)\b/.test(line))) &&
            /\bbg-(?!line\b|transparent\b)/.test(line) &&
            !line.includes('!item.readAt') &&
            !/\bUNREAD_MARK\b/.test(line) &&
            !/\bbg-live\b/.test(line),
    },
    {
        id: 'palette-colour',
        why: 'a Tailwind palette colour — colours come from the tokens in resources/css/app.css',
        test: (line) =>
            new RegExp(`\\b(bg|text|border|ring|fill|stroke|from|to|via|outline|divide|decoration|accent|caret)-(${PALETTE})-\\d{2,3}\\b`).test(line),
    },
    {
        // White and black are tokens by role (on-photo,
        // on-accent, scrim, highlight, paper), never by name or as a literal.
        id: 'literal-colour',
        why: 'white, black or a literal colour — use a role token from resources/css/app.css (on-photo, on-accent, scrim, highlight, paper)',
        test: (line) =>
            /(?<![\w-])(?:[a-z-]+:)*(bg|text|border|ring|fill|stroke|from|to|via|outline|divide|decoration|shadow|placeholder)-(white|black)(?![\w-])/.test(
                line,
            ) || /rgba?\(|#[0-9a-fA-F]{6}\b|#[0-9a-fA-F]{3}\b(?!-)/.test(line),
    },
    {
        // Tailwind keeps a class or a colour token only where it sees it named in full; one put
        // together at runtime compiles to nothing. A development server that saw the full name once
        // keeps it until it restarts, so the page looks right there and wrong in a build.
        id: 'built-colour-name',
        why: 'a colour class or token named at runtime (`bg-${x}`, `var(--color-${x})`) — write each name out in full',
        test: (line) => /(?<![\w-])(?:[a-z-]+:)*(bg|text|border|ring|fill|stroke|outline|decoration|shadow)-\$\{|--color-\$\{/.test(line),
    },
    {
        // app.css defines no second palette, so these names would compile to
        // nothing.
        id: 'shadcn-colour',
        why: 'a shadcn colour name (background, foreground, card, muted…) — the tokens in resources/css/app.css instead',
        test: (line) =>
            /(?<![\w-])(bg|text|border|ring|ring-offset|fill|stroke|from|to|via|outline|divide)-(background|foreground|card|muted|muted-foreground|accent|input|primary|secondary|popover|destructive|ring)(?![\w-])/.test(
                line,
            ),
    },
];

/**
 * Text a user reads, written into a template instead of a lang file. Read off
 * Vue's own template AST, so `>` inside an attribute cannot fool it. What
 * stays the same in every language is listed here once.
 */
const SAME_IN_EVERY_LANGUAGE = new Set([
    'VideTrack',
    // The key's name on the keyboard.
    'Esc',
    // Sample values, not words.
    'email@example.com',
    'Authorization: Bearer <token>',
    'https://discord.com/api/webhooks/…',
    // Each language named in itself, so a reader finds their own.
    'English',
    'Polski',
]);

const TEXT_ATTRIBUTES = ['placeholder', 'title', 'aria-label', 'alt', 'label'];

function hardcodedTexts(source) {
    const { descriptor } = parse(source);
    const problems = [];
    const visit = (node) => {
        if (node.type === 2 && /\p{L}{2,}/u.test(node.content) && !SAME_IN_EVERY_LANGUAGE.has(node.content.trim())) {
            problems.push({
                line: node.loc.start.line,
                why: `text "${node.content.trim()}" written into the template — name it in lang/{pl,en}/*.php`,
            });
        }

        for (const prop of node.props ?? []) {
            const value = prop.type === 6 && TEXT_ATTRIBUTES.includes(prop.name) ? prop.value?.content : undefined;

            if (value && /\p{L}{2,}/u.test(value) && !SAME_IN_EVERY_LANGUAGE.has(value)) {
                problems.push({ line: prop.loc.start.line, why: `${prop.name}="${value}" written into the template — bind it to trans()` });
            }
        }

        (node.children ?? []).forEach(visit);
    };

    if (descriptor.template?.ast) {
        visit(descriptor.template.ast);
    }

    return problems;
}

/** Every <img> tag needs alt, width, height and loading (CLS and lazy loading). */
function imageProblems(source) {
    const problems = [];

    for (const match of source.matchAll(/<img\b[^>]*>/gs)) {
        const tag = match[0];
        const missing = ['alt', 'width', 'height', 'loading'].filter((attribute) => !new RegExp(`(^|\\s)(:|v-bind:)?${attribute}=`).test(tag));

        if (missing.length > 0) {
            problems.push({ line: source.slice(0, match.index).split('\n').length, why: `<img> without ${missing.join(', ')}` });
        }
    }

    return problems;
}

function files(dir) {
    return readdirSync(dir).flatMap((name) => {
        const path = join(dir, name);

        if (statSync(path).isDirectory()) {
            return path.endsWith('components/ui') ? [] : files(path);
        }

        return /\.(vue|ts)$/.test(name) && !name.endsWith('.test.ts') ? [path] : [];
    });
}

const violations = [];

for (const path of files(SOURCE)) {
    const file = relative(ROOT, path);
    const source = readFileSync(path, 'utf8');

    source.split('\n').forEach((line, index) => {
        for (const rule of RULES) {
            if (rule.test(line)) {
                violations.push({ rule: rule.id, file, line: index + 1, why: rule.why });
            }
        }
    });

    for (const problem of imageProblems(source)) {
        violations.push({ rule: 'img-attributes', file, ...problem });
    }

    if (path.endsWith('.vue')) {
        for (const problem of hardcodedTexts(source)) {
            violations.push({ rule: 'hardcoded-text', file, ...problem });
        }
    }
}

// A sentence a user reads takes a colon, a comma or the en dash, never the em
// dash. Laravel's own groups are the framework's wording, not ours.
const FRAMEWORK_GROUPS = new Set(['auth', 'pagination', 'passwords', 'validation']);

for (const locale of ['en', 'pl']) {
    const dir = join(ROOT, 'lang', locale);

    for (const name of readdirSync(dir).filter((file) => file.endsWith('.php') && !FRAMEWORK_GROUPS.has(file.replace(/\.php$/, '')))) {
        const file = relative(ROOT, join(dir, name));

        readFileSync(join(dir, name), 'utf8')
            .split('\n')
            .forEach((line, index) => {
                if (/=>\s*'.*—/.test(line)) {
                    violations.push({ rule: 'em-dash', file, line: index + 1, why: "'—' in a UI text: a colon, a comma or '–' instead" });
                }
            });
    }
}

const unexpected = violations.filter((violation) => !EXCEPTIONS.has(`${violation.rule}|${violation.file}`));

for (const violation of unexpected) {
    console.log(`${violation.file}:${violation.line}  [${violation.rule}] ${violation.why}`);
}

const stale = [...EXCEPTIONS].filter((key) => !violations.some((violation) => `${violation.rule}|${violation.file}` === key));

for (const key of stale) {
    console.log(`fixed, remove from EXCEPTIONS: ${key}`);
}

if (unexpected.length > 0 || stale.length > 0) {
    console.log(`\n${unexpected.length} UI rule violation(s), ${stale.length} stale exception(s).`);
    process.exit(1);
}

console.log('UI rules hold.');
