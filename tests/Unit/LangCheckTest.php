<?php

use App\Support\LangCheck;
use App\Support\LangFiles;

/**
 * A throwaway lang directory and source tree.
 *
 * @param  array<string, string>  $files  relative path => contents
 */
function langFixture(array $files): string
{
    $root = scratchDirectory('lang-check-');

    foreach ($files as $path => $contents) {
        $dir = dirname("{$root}/{$path}");
        is_dir($dir) || mkdir($dir, 0777, true);
        file_put_contents("{$root}/{$path}", $contents);
    }

    return $root;
}

function langCheck(string $root): LangCheck
{
    return new LangCheck(new LangFiles("{$root}/lang"), ["{$root}/src"]);
}

test('group keys present in every locale and named in the code pass', function () {
    $root = langFixture([
        'lang/en/media.php' => "<?php return ['apple' => 'Apple', 'count_banana' => ':count banana|:count bananas'];",
        'lang/pl/media.php' => "<?php return ['apple' => 'Jabłko', 'count_banana' => ':count banan|:count bananów'];",
        'lang/en/tasks.php' => "<?php return ['daily' => 'Daily'];",
        'lang/pl/tasks.php' => "<?php return ['daily' => 'Codziennie'];",
        // The framework's own strings and groups are not ours to account for.
        'lang/en.json' => '{"Hello!": "Hello!"}',
        'lang/pl.json' => '{"Hello!": "Witaj!"}',
        'lang/en/auth.php' => "<?php return ['failed' => 'Nope'];",
        'lang/pl/auth.php' => "<?php return ['failed' => 'Nie'];",
        'src/Page.vue' => "{{ trans('media.apple') }} {{ transChoice(\"media.count_banana\", 2) }}",
        // A key passed around as data still counts as named.
        'src/Tasks.php' => "<?php \$frequency = 'tasks.daily'; echo 'x'.__(\"{\$dynamic}\"); \$translator->trans('not checked');",
    ]);

    expect(langCheck($root)->problems())->toBe([]);
});

test('a key one locale lacks is reported', function () {
    $root = langFixture([
        'lang/en/media.php' => "<?php return ['apple' => 'Apple', 'pear' => 'Pear'];",
        'lang/pl/media.php' => "<?php return ['apple' => 'Jabłko'];",
        'src/Page.vue' => "{{ trans('media.apple') }} {{ trans('media.pear') }}",
    ]);

    expect(langCheck($root)->problems())->toBe([
        'pl: missing "media.pear" (present in another locale)',
        "pl: missing \"media.pear\" (used in {$root}/src/Page.vue)",
    ]);
});

test('a key the code names but no locale has, and a plain sentence as a key, are reported', function () {
    $root = langFixture([
        'lang/en/media.php' => '<?php return [];',
        'lang/pl/media.php' => '<?php return [];',
        // Concatenation in PHP puts a dot right before the call.
        'src/lib.php' => "<?php echo 'a'.__('media.missing'); __(\n    'Don\\'t panic',\n);",
    ]);

    expect(langCheck($root)->problems())->toBe([
        "en: missing \"media.missing\" (used in {$root}/src/lib.php)",
        "pl: missing \"media.missing\" (used in {$root}/src/lib.php)",
        "\"Don't panic\" is not a group key — name the text in lang/{locale}/{group}.php (used in {$root}/src/lib.php)",
    ]);
});

test('a key chosen by a condition is checked like a plain one, a fallback text is left alone', function () {
    $root = langFixture([
        'lang/en/media.php' => "<?php return ['one' => 'One'];",
        'lang/pl/media.php' => "<?php return ['one' => 'Jeden'];",
        'src/page.ts' => "transChoice(many ? 'media.one' : 'media.typo', n); trans(names[code] ?? 'Unknown');",
    ]);

    expect(langCheck($root)->problems())->toBe([
        "en: missing \"media.typo\" (used in {$root}/src/page.ts)",
        "pl: missing \"media.typo\" (used in {$root}/src/page.ts)",
    ]);
});

test('a key of ours nothing names is reported as unused', function () {
    $root = langFixture([
        'lang/en/media.php' => "<?php return ['apple' => 'Apple', 'stale' => 'Stale'];",
        'lang/pl/media.php' => "<?php return ['apple' => 'Jabłko', 'stale' => 'Stare'];",
        'src/Page.vue' => "{{ trans('media.apple') }}",
    ]);

    expect(langCheck($root)->problems())->toBe(['"media.stale" is not used anywhere']);
});
