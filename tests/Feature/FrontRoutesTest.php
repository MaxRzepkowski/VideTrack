<?php

use Tighten\Ziggy\Ziggy;

/*
 * The front names routes as strings (Ziggy's `route('…')`), and nothing in
 * vue-tsc checks them: a renamed or removed route only fails in the browser.
 * Every name the front spells must be in the route map the page carries — a
 * literal exactly, a template (`ui.${resource}.follow.update`) by at least one
 * route — so a route kept out of the map fails here too.
 */

/** @return array<string, string> repo-relative path => contents of the front's own code */
function frontSources(): array
{
    $root = dirname(__DIR__, 2);
    $sources = [];
    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator("{$root}/resources/js", FilesystemIterator::SKIP_DOTS));

    foreach ($files as $file) {
        $path = substr($file->getPathname(), strlen("{$root}/"));

        if (in_array($file->getExtension(), ['ts', 'vue'], true) && ! str_ends_with($path, '.test.ts') && ! str_starts_with($path, 'resources/js/test/')) {
            $sources[$path] = (string) file_get_contents($file->getPathname());
        }
    }

    return $sources;
}

test('every route the front names exists', function () {
    $carried = array_keys((new Ziggy)->toArray()['routes']);
    $missing = [];

    foreach (frontSources() as $path => $source) {
        // `route('…')` calls, and the navigation tree's `route: '…'` entries.
        preg_match_all('/\broute(?:\(\s*|:\s*)([\'"`])([^\'"`]+)\1/', $source, $matches, PREG_SET_ORDER);

        foreach ($matches as [, $quote, $name]) {
            $pattern = '/^'.preg_replace('/\\\\\$\\\\\{[^}]*\\\\\}/', '[a-z-]+', preg_quote($name, '/')).'$/';
            $known = $quote === '`' ? preg_grep($pattern, $carried) !== [] : in_array($name, $carried, true);

            if (! $known) {
                $missing[] = "{$path}: {$name}";
            }
        }
    }

    expect($missing)->toBe([]);
});
