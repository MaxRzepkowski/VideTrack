<?php

use App\Actions\Library\MarkWatched;
use App\Actions\Library\SyncListMembership;
use App\Catalog\Data\LocalizedTitle;
use App\Catalog\TitleStore;
use App\Library\WatchedAt;
use App\Models\MediaItem;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;

/** The film persisted the way a click in an English UI does it. */
function persistedPolishFilm(): MediaItem
{
    Http::fake(['*/movie/31407*' => Http::response(tmdbPolishFilm()), ...providerDictionaryFakes()]);

    return app(TitleStore::class)->persist('movie', '31407', 'en');
}

test('a persisted title keeps its name and poster in every UI language, whichever language persisted it', function () {
    $item = persistedPolishFilm();

    expect($item->title)->toBe('Teddy Bear')
        ->and($item->fresh()?->localized_titles)->toEqual([
            'en' => new LocalizedTitle('Teddy Bear', '/en-poster.jpg'),
            'pl' => new LocalizedTitle('Miś', '/pl-poster.jpg'),
        ]);
});

test('a reader gets their language, then the fallback one, then the row', function () {
    $item = MediaItem::factory()->movie()->make([
        'title' => 'Row title',
        'poster_path' => '/row.jpg',
        'localized_titles' => ['en' => new LocalizedTitle('English title', '/en.jpg'), 'pl' => new LocalizedTitle('Polski tytuł')],
    ]);

    expect($item->titleIn('pl'))->toBe('Polski tytuł')
        // Polish has a name but no poster of its own: the row's poster, not a blank.
        ->and($item->posterIn('pl'))->toBe('/row.jpg')
        ->and($item->titleIn('de'))->toBe('English title')
        ->and($item->posterIn('de'))->toBe('/en.jpg')
        ->and(MediaItem::factory()->movie()->make(['title' => 'Row title'])->titleIn('pl'))->toBe('Row title');
});

test('a list card names the title and shows its poster in the language the reader switched to', function (string $locale, string $title, string $poster) {
    $item = persistedPolishFilm();
    $user = User::factory()->create(['locale' => $locale]);
    $list = $user->watchlist();
    app(SyncListMembership::class)->execute($user, $item, [$list->id]);

    $media = $this->actingAs($user)
        ->get(listPage($list))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('lists/ListDetails'))
        ->viewData('page')['props']['items'][0]->media;

    expect($media->title)->toBe($title)
        ->and($media->posterPath)->toBe($poster);
})->with([
    'polish' => ['pl', 'Miś', '/pl-poster.jpg'],
    'english' => ['en', 'Teddy Bear', '/en-poster.jpg'],
]);

test('the palette finds a title by the name on the reader\'s card and by the row\'s own', function () {
    $item = persistedPolishFilm();
    $user = User::factory()->create(['locale' => 'pl']);
    app(MarkWatched::class)->execute($user, $item, WatchedAt::at(CarbonImmutable::parse('2026-01-01')));

    foreach (['Miś', 'Teddy'] as $query) {
        $this->actingAs($user)
            ->getJson(route('palette', ['q' => $query]))
            ->assertOk()
            ->assertJsonPath('results.0.title', 'Miś');
    }
});
