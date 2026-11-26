<?php

use App\Support\Locales;

test('a locale is declared as written, its region only where it has one', function (string $locale, string $declared) {
    expect(Locales::declaredLanguage($locale))->toBe($declared);
})->with([
    'a language alone' => ['pl', 'pl'],
    'a language and its region' => ['pt_BR', 'pt-BR'],
]);
