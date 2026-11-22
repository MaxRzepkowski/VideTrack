<?php

use App\Models\User;
use App\Support\FrontTranslations;

/*
 * The front's texts come from the server, one language at a time, under an
 * address the page shell names and a browser may keep for good.
 */

/** Language files of their own, so a test can leave a text untranslated or change one. */
function useLangFiles(array $groupsByLocale): void
{
    $path = scratchDirectory('lang');

    foreach ($groupsByLocale as $locale => $groups) {
        mkdir("{$path}/{$locale}");

        foreach ($groups as $group => $lines) {
            file_put_contents("{$path}/{$locale}/{$group}.php", '<?php return '.var_export($lines, true).';');
        }
    }

    app()->useLangPath($path);
}

test("the page names its reader's language at an address that carries the texts' fingerprint", function () {
    $translations = app(FrontTranslations::class);
    $english = route('translations', ['locale' => 'en', 'version' => $translations->version('en')], absolute: false);
    $polish = route('translations', ['locale' => 'pl', 'version' => $translations->version('pl')], absolute: false);

    $this->get(route('login'))
        ->assertOk()
        ->assertSee('<link rel="preload" href="'.$english.'"', escape: false)
        ->assertInertia(fn ($page) => $page->where('translations', $english));

    $this->actingAs(User::factory()->create(['locale' => 'pl']))->get(route('about'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('translations', $polish));
});

test('the address answers every text of the language, for any browser to keep, without a session', function () {
    $version = app(FrontTranslations::class)->version('pl');

    $response = $this->get(route('translations', ['locale' => 'pl', 'version' => $version]));

    $response->assertOk()
        ->assertHeader('Content-Type', 'application/json')
        ->assertCookieMissing(config('session.cookie'))
        ->assertJsonPath(['common.show_more'], trans('common.show_more', locale: 'pl'));

    expect($response->headers->get('Cache-Control'))->toContain('immutable')->toContain('public')
        ->and($response->headers->getCookies())->toBe([]);
});

test('a text not yet translated reads in the fallback language, never as its key', function () {
    useLangFiles([
        'en' => ['shelf' => ['title' => 'Shelf', 'empty' => 'Nothing here']],
        'pl' => ['shelf' => ['title' => 'Półka']],
    ]);

    $texts = json_decode(app(FrontTranslations::class)->json('pl'), true);

    expect($texts)->toBe(['shelf.title' => 'Półka', 'shelf.empty' => 'Nothing here']);
});

test('the fingerprint moves with any text, so the old address is never read again', function () {
    useLangFiles(['en' => ['shelf' => ['title' => 'Shelf']]]);
    $before = app(FrontTranslations::class)->version('en');

    useLangFiles(['en' => ['shelf' => ['title' => 'Shelves']]]);

    expect(app(FrontTranslations::class)->version('en'))->not->toBe($before);
});

test('an outdated fingerprint gets the current texts, kept by nobody', function () {
    $this->get(route('translations', ['locale' => 'en', 'version' => str_repeat('0', FrontTranslations::VERSION_LENGTH)]))
        ->assertOk()
        ->assertHeader('Cache-Control', 'no-store, private');
});

test('a language the instance does not speak has no texts', function () {
    $this->get(route('translations', ['locale' => 'xx', 'version' => str_repeat('0', FrontTranslations::VERSION_LENGTH)]))->assertNotFound();
});
