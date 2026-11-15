<?php

/*
 * The code rules that live in its text rather than in its dependencies, so
 * `arch()` cannot see them. Each exception says why it is
 * there; the lists only shrink.
 */

/** @return array<string, string> repo-relative path => contents, for every PHP file under app/ */
function appSources(): array
{
    static $sources = null;

    if ($sources !== null) {
        return $sources;
    }

    $root = dirname(__DIR__, 2);
    $sources = [];
    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator("{$root}/app", FilesystemIterator::SKIP_DOTS));

    foreach ($files as $file) {
        if ($file->getExtension() === 'php') {
            $sources[substr($file->getPathname(), strlen("{$root}/"))] = (string) file_get_contents($file->getPathname());
        }
    }

    ksort($sources);

    return $sources;
}

/**
 * Files where a pattern matches, outside the given path prefixes and files.
 *
 * @param  list<string>  $allowed  path prefixes or exact paths
 * @return list<string>
 */
function filesMatching(string $pattern, array $allowed = []): array
{
    $hits = [];

    foreach (appSources() as $path => $contents) {
        foreach ($allowed as $prefix) {
            if (str_starts_with($path, $prefix)) {
                continue 2;
            }
        }

        if (preg_match($pattern, $contents) === 1) {
            $hits[] = $path;
        }
    }

    return $hits;
}

test('HTTP to outside services goes through a *Client', function () {
    $offenders = array_filter(
        filesMatching('/\bHttp::|use Illuminate\\\\Support\\\\Facades\\\\Http;/', [
            'app/Catalog/Providers/Concerns/SendsHttpRequests.php',
        ]),
        fn (string $path): bool => ! str_ends_with($path, 'Client.php'),
    );

    expect(array_values($offenders))->toBe([]);
});

test('provider codes are spoken only by providers, integrations and service providers', function () {
    $codes = implode('|', ['tmdb', 'igdb', 'hardcover', 'mal', 'comicvine', 'tvmaze', 'wikidata', 'justwatch']);

    expect(filesMatching("/'({$codes})'/", [
        'app/Catalog/Providers/',
        'app/Catalog/Wikidata/',
        'app/Integrations/',
        'app/Providers/',
    ]))->toBe([]);
});

test('a broad catch reports or rethrows — never a silent line in a summary', function () {
    $silent = [];

    foreach (appSources() as $path => $contents) {
        preg_match_all('/catch \(\\\\?(?:Throwable|Exception)\b[^)]*\)\s*\{/', $contents, $matches, PREG_OFFSET_CAPTURE);

        foreach ($matches[0] as [$catch, $offset]) {
            $depth = 0;
            $body = '';

            for ($i = $offset + strlen($catch) - 1; $i < strlen($contents); $i++) {
                $depth += match ($contents[$i]) {
                    '{' => 1,
                    '}' => -1,
                    default => 0,
                };
                $body .= $contents[$i];

                if ($depth === 0) {
                    break;
                }
            }

            if (! str_contains($body, 'report(') && ! preg_match('/\bthrow\b/', $body)) {
                $silent[] = $path.':'.(substr_count(substr($contents, 0, $offset), "\n") + 1);
            }
        }
    }

    expect($silent)->toBe([]);
});

test('errors are never silenced with @', function () {
    expect(filesMatching('/(?<![\w\'"])@(\$|[a-z_]+\()/'))->toBe([]);
});

test('the clock is now() and today(), which tests can travel', function () {
    expect(filesMatching('/(?<![\w>:$])(?<!function )(time|date|strtotime|microtime|hrtime)\(|new \\\\?DateTime(Immutable)?\(\)|get(Next|Previous)RunDate\(\)/'))->toBe([]);
});

test('the library is written through its actions alone', function () {
    $models = 'Play|Rating|Favorite|Follow|ListItem|MediaUserState|WatchCycle|MediaList';
    $writes = 'create|forceCreate|insert|insertOrIgnore|upsert|firstOrCreate|updateOrCreate|update|delete|forceDelete|increment|decrement|createMany';
    $relations = 'plays|ratings|favorites|follows|watchCycles|mediaUserStates|items|lists';

    $offenders = filesMatching(
        "/\\b(?:{$models})::(?:query\\(\\))?[^;]*?->\\s*(?:{$writes})\\(|\\b(?:{$models})::(?:create|insert|upsert|firstOrCreate|updateOrCreate|destroy)\\(|->(?:{$relations})\\(\\)[^;]*?->\\s*(?:{$writes})\\(/s",
        [
            'app/Actions/Library/',
            // The rules the actions share (attempts, backfill, sponsorships, list membership).
            'app/Library/',
            // A collection's list belongs to no user: it mirrors the provider.
            'app/Actions/Media/SyncCollectionList.php',
            // A new account starts with its watchlist.
            'app/Actions/Users/CreateAccount.php',
        ],
    );

    expect($offenders)->toBe([]);
});

test('PHPStan is answered with a typed read, never silenced', function () {
    // A value whose type the analysis cannot see is read through a checked
    // reader (App\Support\Payload, App\Support\Numbers, App\Support\QueryRow),
    // not hidden from it — neither in the code it analyses nor by its configuration.
    $root = dirname(__DIR__, 2);
    $silenced = [];

    foreach (['app', 'config', 'database', 'routes'] as $directory) {
        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator("{$root}/{$directory}", FilesystemIterator::SKIP_DOTS));

        foreach ($files as $file) {
            if ($file->getExtension() === 'php' && str_contains((string) file_get_contents($file->getPathname()), '@phpstan-ignore')) {
                $silenced[] = substr($file->getPathname(), strlen("{$root}/"));
            }
        }
    }

    expect($silenced)->toBe([])
        ->and((string) file_get_contents("{$root}/phpstan.neon"))->not->toMatch('/ignoreErrors|baseline/i');
});

test('PHPStan runs at its top level', function () {
    // Like the coverage floors, the level only goes up: 10 also checks the
    // values nobody typed (a raw row's column, an untyped closure parameter).
    expect((string) file_get_contents(dirname(__DIR__, 2).'/phpstan.neon'))->toMatch('/^\s*level:\s*(10|max)\s*$/m');
});
