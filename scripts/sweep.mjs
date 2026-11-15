// The debt against the code rules that is still in the code, counted. Each
// rule is a search over the source; the counts live in sweep-baseline.json
// and may only go down. A count above the baseline fails (new debt); one
// below it fails too until the baseline is lowered (`--update`), so a gain
// cannot be given back unnoticed. Run: node scripts/sweep.mjs [--update] [--files]
import { readdirSync, readFileSync, statSync, writeFileSync } from 'node:fs';
import { join, relative } from 'node:path';

const ROOT = new URL('..', import.meta.url).pathname;
const BASELINE = join(ROOT, 'scripts/sweep-baseline.json');

function files(dir, extensions) {
    return readdirSync(dir).flatMap((name) => {
        const path = join(dir, name);

        if (statSync(path).isDirectory()) {
            return name === 'ui' && dir.endsWith('components') ? [] : files(path, extensions);
        }

        return extensions.some((extension) => name.endsWith(extension)) && !name.endsWith('.test.ts') ? [relative(ROOT, path)] : [];
    });
}

const PHP = files(join(ROOT, 'app'), ['.php']);
const FRONT = files(join(ROOT, 'resources/js'), ['.ts', '.vue']).filter((path) => !path.startsWith('resources/js/test/'));
const read = (path) => readFileSync(join(ROOT, path), 'utf8');
const outside = (prefixes) => (path) => !prefixes.some((prefix) => path.startsWith(prefix));

/** Occurrences of a pattern per file. */
function matches(paths, pattern) {
    const hits = {};

    for (const path of paths) {
        const count = (read(path).match(new RegExp(pattern, 'gm')) ?? []).length;

        if (count > 0) {
            hits[path] = count;
        }
    }

    return hits;
}

/** Files over a line threshold, each counted once (the listing shows their length). */
function longerThan(paths, lines) {
    return Object.fromEntries(
        paths
            .map((path) => [path, read(path).split('\n').length])
            .filter(([, length]) => length > lines)
            .map(([path, length]) => [`${path}, ${length} lines`, 1]),
    );
}

/**
 * Wire shapes the front describes by hand beside the generated ones: an
 * interface or an object type in types/, or one a page declares for its own
 * props, unless its doc marks it `@view` (a shape only the front builds). An
 * alias of a generated type is not a second description and is not counted.
 */
function handWrittenShapes() {
    const hits = {};
    const described = (path) =>
        (path.startsWith('resources/js/types/') && path !== 'resources/js/types/generated.ts') || path.startsWith('resources/js/pages/');

    for (const path of FRONT.filter(described)) {
        const lines = read(path).split('\n');
        let count = 0;

        lines.forEach((line, index) => {
            if (!/^(export )?(interface \w+|type \w+(<[^=]*>)? = \{)/.test(line)) {
                return;
            }

            let doc = '';

            for (let above = index - 1; above >= 0 && /^\s*(\/\*\*|\*|\/\/)/.test(lines[above]); above--) {
                doc += lines[above];
            }

            count += doc.includes('@view') ? 0 : 1;
        });

        if (count > 0) {
            hits[path] = count;
        }
    }

    return hits;
}

const PROVIDER_CODES = "'(tmdb|igdb|hardcover|mal|comicvine|tvmaze|wikidata|justwatch)'";
const TYPE_LIST = "\\[\\s*'(show|movie|game|book|manga|comic)',\\s*'(show|movie|game|book|manga|comic|season|episode)'";
// A type spelled as a key or a case: a map or a switch over media types beside the registry.
const TYPE_KEYED =
    "^\\s*(show|movie|game|book|manga|comic):\\s*\\{|case '(show|movie|game|book|manga|comic)':[^}]{0,200}?case '(show|movie|game|book|manga|comic|season|episode)':|'(show|movie|game|book|manga|comic)' \\| '(show|movie|game|book|manga|comic|season|episode)'";
const PROVIDER_HOME = ['app/Catalog/Providers/', 'app/Catalog/Wikidata/', 'app/Integrations/', 'app/Providers/'];

const RULES = {
    // Rules of the code.
    'metadata read outside ItemMetadata': () =>
        matches(PHP.filter(outside(['app/Catalog/ItemMetadata.php', 'app/Catalog/Providers/'])), "->metadata\\[|metadata, '|\\$metadata\\["),
    "?? '' in the domain": () => matches(PHP.filter(outside(['app/Http/'])), "\\?\\? ''"),
    'payload reader copied (stringOrNull and kin)': () =>
        // The two shared readers: Payload for outside data, QueryRow for our own raw rows.
        matches(PHP.filter(outside(['app/Support/Payload.php', 'app/Support/QueryRow.php'])), 'function (stringOrNull|stringValue|intOrNull|floatOrNull|nullableString)\\('),
    'exception message stored or logged': () => matches(PHP, '->getMessage\\(\\)'),
    'query built in a controller': () =>
        matches(
            PHP.filter((path) => path.startsWith('app/Http/Controllers/')),
            '::query\\(\\)|DB::table|->whereIn\\(|->orderBy\\(',
        ),
    'provider code in the front': () =>
        matches(
            // The brand marks are keyed by website, some of which are also providers.
            FRONT.filter(outside(['resources/js/types/generated.ts', 'resources/js/lib/serviceGlyphs.ts'])),
            `${PROVIDER_CODES}|^\\s*(tmdb|igdb|hardcover|mal|comicvine|tvmaze|wikidata|justwatch):\\s*\\{`,
        ),
    'type list written out (front)': () =>
        matches(FRONT.filter(outside(['resources/js/lib/mediaTypes.ts', 'resources/js/types/generated.ts'])), `${TYPE_LIST}|${TYPE_KEYED}`),
    'type list written out (core)': () => matches(PHP.filter(outside(PROVIDER_HOME)), TYPE_LIST),
    'non-null assertion or double cast (front)': () => matches(FRONT, 'as unknown as|@ts-ignore|@ts-expect-error'),
    'wire shape written by hand (front)': handWrittenShapes,
    'lint switched off': () => matches([...PHP, ...FRONT], 'eslint-disable|phpstan-ignore|@codingStandardsIgnore'),
    // Size thresholds (single responsibility): files to think about, not verdicts.
    'PHP class over 400 lines': () => longerThan(PHP, 400),
    'controller over 150 lines': () =>
        longerThan(
            PHP.filter((path) => path.startsWith('app/Http/Controllers/')),
            150,
        ),
    'Vue component over 400 lines': () =>
        longerThan(
            FRONT.filter((path) => path.endsWith('.vue')),
            400,
        ),
};

const counts = {};
const details = {};

for (const [rule, measure] of Object.entries(RULES)) {
    details[rule] = measure();
    counts[rule] = Object.values(details[rule]).reduce((sum, count) => sum + count, 0);
}

if (process.argv.includes('--update')) {
    writeFileSync(BASELINE, `${JSON.stringify(counts, null, 4)}\n`);
    console.log('Baseline written.');
    process.exit(0);
}

const baseline = JSON.parse(readFileSync(BASELINE, 'utf8'));
let failed = false;

for (const [rule, count] of Object.entries(counts)) {
    const was = baseline[rule] ?? 0;
    const mark = count > was ? '  ✗ grew' : count < was ? '  ✗ shrank — lower the baseline (--update)' : '';

    failed ||= count !== was;
    console.log(`${String(count).padStart(5)}  ${rule}${mark}`);

    if (process.argv.includes('--files') || count > was) {
        for (const [path, hits] of Object.entries(details[rule])) {
            console.log(`         ${path} (${hits})`);
        }
    }
}

for (const rule of Object.keys(baseline).filter((rule) => !(rule in counts))) {
    console.log(`  rule gone, drop it from the baseline: ${rule}`);
    failed = true;
}

process.exit(failed ? 1 : 0);
