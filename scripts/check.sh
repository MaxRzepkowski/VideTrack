#!/bin/sh
# Every quality gate in verify mode (nothing is rewritten), one summary at the end.
# Run inside the app container: docker compose run --rm app composer check
#
# Two lanes run side by side, the PHP gates and the front's; each gate writes
# to a log of its own, printed in one fixed order once both lanes are done, so
# the report reads the same however the lanes interleaved. The caches live in
# the bind-mounted repository (ignored by git), so they outlive the container.

logs=$(mktemp -d)
trap 'rm -rf "$logs"' EXIT

run() {
    name="$1"
    shift
    started=$(date +%s)
    if ! "$@" >"$logs/$name.log" 2>&1; then
        echo "$name" >>"$logs/failed"
    fi
    echo "$(($(date +%s) - started))" >"$logs/$name.time"
}

# The suite with coverage: pcov switched on for this run only. The floor is
# the coverage reached so far and only ever goes up. The per-class table is
# noise on a pass, so a pass prints the totals and a failure prints it all.
PHP_COVERAGE_MIN=96.6
# Points a result may sit above its floor before the floor must be raised.
COVERAGE_SLACK=0.3

pest_with_coverage() {
    # One process per core; each worker needs pcov switched on itself.
    output=$(php -d pcov.enabled=1 vendor/bin/pest --compact --parallel --passthru-php="-d pcov.enabled=1" --coverage --min="$PHP_COVERAGE_MIN" 2>&1)
    status=$?
    if [ $status -eq 0 ]; then
        printf '%s\n' "$output" | grep -E 'Tests:|Total:'
        # The floor only ever goes up: a result well above it means the floor
        # was left behind, and the next drop would pass unnoticed.
        total=$(printf '%s\n' "$output" | sed 's/\x1b\[[0-9;]*m//g' | grep -Eo 'Total: +[0-9.]+' | grep -Eo '[0-9.]+$')
        # A worker without a coverage driver passes `--min` without measuring
        # anything: no total is a failure, not a pass.
        if [ -z "$total" ]; then
            echo 'The suite ran without a coverage total: is pcov on in every worker?'
            return 1
        fi
        if awk "BEGIN { exit !($total - $PHP_COVERAGE_MIN > $COVERAGE_SLACK) }"; then
            echo "Coverage is $total%, the floor $PHP_COVERAGE_MIN%: raise PHP_COVERAGE_MIN in scripts/check.sh."
            return 1
        fi
    else
        printf '%s\n' "$output"
    fi
    return $status
}

# The types the front imports from PHP: generated beside the committed file
# (inside the repository, so Prettier formats it by the project's rules) and
# compared, so an enum or DTO changed without re-running
# `php artisan typescript:transform` fails here instead of in the browser.
types_current() {
    dir=$(mktemp -d resources/js/types/.check-XXXXXX)
    TYPESCRIPT_OUTPUT_DIRECTORY="$PWD/$dir" php artisan typescript:transform >/dev/null || { rm -rf "$dir"; return 1; }
    if ! diff -u resources/js/types/generated.ts "$dir/generated.ts"; then
        echo 'resources/js/types/generated.ts is out of date: run php artisan typescript:transform'
        rm -rf "$dir"
        return 1
    fi
    rm -rf "$dir"
}

# The front's floors live in vitest.config.ts and are held to the same rule.
vitest_with_coverage() {
    output=$(npx vitest run --coverage 2>&1)
    status=$?
    printf '%s\n' "$output"
    [ $status -eq 0 ] || return $status
    printf '%s\n' "$output" | sed 's/\x1b\[[0-9;]*m//g' | node scripts/coverage-floors.mjs "$COVERAGE_SLACK"
}

php_lane() {
    run pint vendor/bin/pint --test --parallel --cache-file=storage/framework/cache/pint.cache
    run phpstan php -d memory_limit=1G vendor/bin/phpstan analyse --no-progress
    run pest pest_with_coverage
    run lang php artisan lang:check
}

front_lane() {
    run eslint npx eslint . --max-warnings=0 --cache --cache-strategy content --cache-location node_modules/.cache/eslint/
    run prettier npx prettier --check --cache --cache-strategy content --cache-location node_modules/.cache/prettier/.cache resources/
    run vue-tsc npx vue-tsc --noEmit
    run vitest vitest_with_coverage
    run ui-rules node scripts/ui-rules.mjs
    run sweep node scripts/sweep.mjs
    run publish node scripts/publish-guard.mjs
}

# The types before both lanes: their scratch copy sits inside resources/js,
# where ESLint, Prettier and the front-route test would come across it.
run types types_current

php_lane &
front_lane &
wait

for name in pint phpstan pest lang types eslint prettier vue-tsc vitest ui-rules sweep publish; do
    printf '\n\033[1m== %s\033[0m (%ss)\n' "$name" "$(cat "$logs/$name.time")"
    cat "$logs/$name.log"
done

failed=""
[ -f "$logs/failed" ] && failed=" $(tr '\n' ' ' <"$logs/failed" | sed 's/ $//')"

if [ -n "$failed" ]; then
    printf '\n\033[31mFailed:%s\033[0m\n' "$failed"
    exit 1
fi

# A local stamp for tooling that wants to know the gates passed on these
# files; written only where such tooling lives.
if [ -d .claude ]; then
    touch .claude/check-passed
fi

printf '\n\033[32mAll gates passed.\033[0m\n'
