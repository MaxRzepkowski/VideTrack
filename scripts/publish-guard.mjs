#!/usr/bin/env node
// What may be published: every file git tracks (or would add) is checked
// against what must never reach the public repository: working notes, local
// tooling, databases and secrets, a path from someone's machine, a private
// e-mail address, a token. Red here means the file must leave the index (or
// the line must change), not that the list should grow.
//
//   node scripts/publish-guard.mjs

import { execFileSync } from 'node:child_process';
import { readFileSync } from 'node:fs';

/** Paths that are never published. */
const FORBIDDEN = [
    [/^CLAUDE\.md$/, 'agent instructions stay local'],
    [/^\.claude\//, 'agent tooling stays local'],
    [/^\.env(?!\.example$)/, 'an environment file holds secrets'],
    [/\.sqlite/, 'a database holds a real library'],
    [/\.bundle$/, 'a git bundle carries the full history'],
    [/^[^/]+\.txt$/, 'a loose note in the root'],
    [/^_/, 'a root entry starting with _ holds local notes'],
    [/^docs\/(?!api\/|screenshots\/|install\.md$|upgrading\.md$|administration\.md$|api\.md$)/, 'docs/ publishes the API spec, screenshots and the guides only'],
    [/^storage\/(?!(.+\/)?\.gitignore$)/, 'the instance data of a development machine'],
];

/** Files the text checks skip, each for a reason. */
const TEXT_EXEMPT = [
    /^(composer|package-lock)\.(lock|json)$/, // package metadata names the packages' authors
    /^LICENSE$/, // the licence text as published by the FSF
];

/**
 * Files that may carry Polish: the translations, tests (real Polish titles
 * and names as fixtures), and data the code matches against.
 */
const POLISH_ALLOWED = [
    /^lang\/pl/, // the Polish translation
    /^tests\//, // fixtures with Polish titles and names
    /\.test\.ts$/, // the same, next to front-end modules
    /^app\/Tracking\/Creators\/PersonTitles\.php$/, // Polish credit phrases to recognise ("we własnej osobie")
    /^resources\/js\/lib\/commands\.ts$/, // folding Polish diacritics for search
    /^scripts\/publish-guard\.mjs$/, // this file: the letters it looks for
];

const POLISH = /[ąćęłńóśźżĄĆĘŁŃÓŚŹŻ]/;

/** Contents that never belong in a public file. */
const SECRETS = [
    [/\/home\/[a-z_][\w.-]*\//, 'a path from a home directory'],
    [/\/Users\/[A-Za-z][\w.-]*\//, 'a path from a home directory'],
    [/hc_pat_[A-Za-z0-9]{8,}/, 'a Hardcover token'],
    [/\bvt_[A-Za-z0-9]{40,}/, 'an API token of this app'],
    [/discord(?:app)?\.com\/api\/webhooks\/\d{5,}\/[\w-]{20,}/, 'a Discord webhook address with its token'],
    [/eyJ[\w-]{10,}\.eyJ[\w-]{10,}\.[\w-]{10,}/, 'a JSON Web Token'],
    [/-----BEGIN [A-Z ]*PRIVATE KEY-----/, 'a private key'],
];

/** An e-mail address is fine only at a domain that reaches nobody. */
const EMAIL = /[A-Za-z0-9._%+-]+@([A-Za-z0-9-]+(?:\.[A-Za-z0-9-]+)+)/g;
const EMAIL_DOMAINS_ALLOWED = /(^|\.)(example\.(com|org|net)|users\.noreply\.github\.com|localhost)$/i;

const files = execFileSync('git', ['ls-files', '--cached', '--others', '--exclude-standard', '-z'], { encoding: 'utf8' })
    .split('\0')
    .filter((file) => file !== '');

const problems = [];

for (const file of files) {
    const forbidden = FORBIDDEN.find(([pattern]) => pattern.test(file));

    if (forbidden) {
        problems.push(`${file}: ${forbidden[1]}`);
        continue;
    }

    if (TEXT_EXEMPT.some((pattern) => pattern.test(file))) {
        continue;
    }

    let content;

    try {
        content = readFileSync(file);
    } catch {
        // Deleted in the working tree but still in the index: git shows that.
        continue;
    }

    // Binary files (images, fonts) carry no readable text to check.
    if (content.subarray(0, 8000).includes(0) || /\.(png|jpe?g|gif|webp|ico|woff2?|ttf|pdf)$/i.test(file)) {
        continue;
    }

    const text = content.toString('utf8');
    const lines = text.split('\n');

    lines.forEach((line, index) => {
        const where = `${file}:${index + 1}`;

        if (POLISH.test(line) && !POLISH_ALLOWED.some((pattern) => pattern.test(file))) {
            problems.push(`${where}: Polish outside the translations (write it in English, or move it to lang/pl)`);
        }

        for (const [pattern, what] of SECRETS) {
            if (pattern.test(line)) {
                problems.push(`${where}: ${what}`);
            }
        }

        for (const match of line.matchAll(EMAIL)) {
            if (!EMAIL_DOMAINS_ALLOWED.test(match[1])) {
                problems.push(`${where}: an e-mail address (${match[0]}); use one at example.com`);
            }
        }
    });
}

if (problems.length > 0) {
    console.error(`${problems.length} thing(s) must not be published:\n`);
    console.error(problems.map((problem) => `  ${problem}`).join('\n'));
    process.exit(1);
}

console.log(`${files.length} files checked, nothing private.`);
