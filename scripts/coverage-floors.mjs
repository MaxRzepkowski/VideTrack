// Reads Vitest's coverage summary on stdin and fails when a result sits more
// than the given slack above its floor in vitest.config.ts: the floors only
// ever go up, and one left behind lets the next drop pass unnoticed.
import { readFileSync } from 'node:fs';

const slack = Number(process.argv[2] ?? '0.3');
const summary = readFileSync(0, 'utf8');
const config = readFileSync(new URL('../vitest.config.ts', import.meta.url), 'utf8');
const floors = config.match(/thresholds:\s*\{([^}]*)\}/)?.[1] ?? '';

let behind = false;

for (const [metric, label] of [
    ['lines', 'Lines'],
    ['statements', 'Statements'],
    ['branches', 'Branches'],
    ['functions', 'Functions'],
]) {
    const floor = Number(floors.match(new RegExp(`${metric}:\\s*([0-9.]+)`))?.[1]);
    const result = Number(summary.match(new RegExp(`${label}\\s*:\\s*([0-9.]+)%`))?.[1]);

    if (Number.isNaN(floor) || Number.isNaN(result)) {
        console.error(`Could not read the ${metric} coverage or its floor.`);
        process.exit(1);
    }

    if (result - floor > slack) {
        console.error(`${label} coverage is ${result}%, the floor ${floor}%: raise it in vitest.config.ts.`);
        behind = true;
    }
}

process.exit(behind ? 1 : 0);
