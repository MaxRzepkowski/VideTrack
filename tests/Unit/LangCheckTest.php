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
    return new LangCheck(new LangFiles("{$root}/lang"), 'en', ["{$root}/src"]);
}

test('group keys present in every locale and named in the code pass', function () {
    $root = langFixture([
        'lang/en/media.php' => "<?php return ['apple' => 'Apple', 'count_banana' => ':count banana|:count bananas'];",
        'lang/pl/media.php' => "<?php return ['apple' => 'Jabłko', 'count_banana' => ':count banan|:count banany|:count bananów'];",
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

/** The two complete languages with one text, a count of bananas, and the code that names both. */
function completeLanguages(): array
{
    return [
        'lang/en/media.php' => "<?php return ['apple' => 'Apple', 'count_banana' => ':count banana|:count bananas'];",
        'lang/pl/media.php' => "<?php return ['apple' => 'Jabłko', 'count_banana' => ':count banan|:count banany|:count bananów'];",
        'src/Page.vue' => "{{ trans('media.apple') }} {{ transChoice('media.count_banana', 2) }}",
    ];
}

test('another language may lag behind, and says how far it is translated', function () {
    $root = langFixture([...completeLanguages(), 'lang/de/media.php' => "<?php return ['apple' => 'Apfel'];"]);

    expect(langCheck($root)->problems())->toBe([])
        ->and(langCheck($root)->coverage())->toBe(['de' => ['translated' => 1, 'total' => 2]]);
});

test('a text another language keeps after the source dropped it is reported', function () {
    $root = langFixture([...completeLanguages(), 'lang/de/media.php' => "<?php return ['apple' => 'Apfel', 'pear' => 'Birne'];"]);

    expect(langCheck($root)->problems())->toBe(['de: "media.pear" is not a text of en']);
});

test('a translation filling in other parameters than its source is reported', function () {
    $root = langFixture([...completeLanguages(), 'lang/de/media.php' => "<?php return ['count_banana' => ':n Banane|:n Bananen'];"]);

    expect(langCheck($root)->problems())->toBe(['de: "media.count_banana" fills in :n, its source :count']);
});

test('a translation that drops every parameter is reported too', function () {
    $root = langFixture([...completeLanguages(), 'lang/de/media.php' => "<?php return ['count_banana' => 'Bananen'];"]);

    expect(langCheck($root)->problems())->toBe(['de: "media.count_banana" fills in no parameters, its source :count']);
});

test('a plural translation has the forms its language counts, or one the count leaves alone', function () {
    $root = langFixture([
        ...completeLanguages(),
        // Russian tells three forms apart, like Polish.
        'lang/ru/media.php' => "<?php return ['count_banana' => ':count банан|:count бананов'];",
        'lang/uk/media.php' => "<?php return ['count_banana' => 'Бананів: :count'];",
        'lang/cs/media.php' => "<?php return ['count_banana' => '{1} :count banán|[2,*] :count banánů'];",
    ]);

    expect(langCheck($root)->problems())->toBe(['ru: "media.count_banana" has 2 plural forms, the language counts 3']);
});

test('a complete language is held to its plural forms as well', function () {
    $root = langFixture([...completeLanguages(), 'lang/pl/media.php' => "<?php return ['apple' => 'Jabłko', 'count_banana' => ':count banan|:count bananów'];"]);

    expect(langCheck($root)->problems())->toBe(['pl: "media.count_banana" has 2 plural forms, the language counts 3']);
});

test("the framework's own plural strings are left as they come", function () {
    $root = langFixture([
        ...completeLanguages(),
        'lang/en/validation.php' => "<?php return ['between' => ':min apple|:min apples'];",
        'lang/pl/validation.php' => "<?php return ['between' => ':min jabłko|:min jabłka|:min jabłek'];",
        'lang/ru/validation.php' => "<?php return ['between' => ':min яблоко|:min яблок'];",
        'lang/en.json' => '{"(and :count more error)": "(and :count more error)|(and :count more errors)|(and :count more errors)"}',
        'lang/pl.json' => '{"(and :count more error)": "(i jeszcze :count błąd)|(i jeszcze :count błędy)|(i jeszcze :count błędów)"}',
        'lang/ru.json' => '{"(and :count more error)": "(и ещё :count ошибка)|(и ещё :count ошибок)"}',
    ]);

    expect(langCheck($root)->problems())->toBe([]);
});
