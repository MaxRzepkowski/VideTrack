<?php

use App\Catalog\BookKind;
use App\Catalog\BookRole;
use App\Catalog\Creators\CreatorKind;
use App\Catalog\Data\CollectionLink;
use App\Catalog\Data\GenreOption;
use App\Catalog\Department;
use App\Catalog\ExternalIdSource;
use App\Catalog\ItemNotFoundException;
use App\Catalog\MatureContent;
use App\Catalog\MediaTypeRegistry;
use App\Catalog\ProviderRegistry;
use App\Catalog\Providers\Hardcover\HardcoverBookKind;
use App\Catalog\Providers\Hardcover\HardcoverLiteraryType;
use App\Catalog\Providers\Hardcover\HardcoverProvider;
use App\Catalog\TitleStore;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

function hardcoverProvider(): HardcoverProvider
{
    return app(HardcoverProvider::class);
}

test('the books module does not exist without a Hardcover token', function () {
    expect(app(MediaTypeRegistry::class)->has('book'))->toBeFalse()
        ->and(fn () => app(ProviderRegistry::class)->get('hardcover'))->toThrow(InvalidArgumentException::class);
});

test('the book type registers once the Hardcover token is configured', function () {
    enableBooksModule();

    $definition = app(MediaTypeRegistry::class)->get('book');

    expect($definition->playable)->toBeTrue()
        ->and($definition->searchable)->toBeTrue()
        ->and($definition->isRoot())->toBeTrue()
        ->and($definition->userStatus)->toBeTrue()
        ->and($definition->progressUnit?->value)->toBe('pages')
        ->and($definition->provider)->toBe('hardcover');
});

test('the key goes to Hardcover as a bearer header, a pasted "Bearer " prefix not doubled', function (string $configured) {
    enableBooksModule();
    config(['tracker.hardcover.api_key' => $configured]);
    fakeHardcover(['Search' => 'search']);

    hardcoverProvider()->search('hobbit', 'en', MatureContent::Suggestive);

    Http::assertSent(fn (Request $request): bool => $request->header('Authorization') === ['Bearer hc_pat_example']);
})->with([
    'the key alone' => ['hc_pat_example'],
    'copied with its header word' => ['Bearer hc_pat_example'],
]);

test('search maps book summaries including coverless hits', function () {
    enableBooksModule();
    fakeHardcover(['Search' => 'search']);

    $results = hardcoverProvider()->search('hobbit', 'en', MatureContent::Suggestive);

    expect($results)->toHaveCount(3)
        ->and($results[0]->type)->toBe('book')
        ->and($results[0]->ref)->toBe('2459845')
        ->and($results[0]->title)->toBe('The Hobbit, or There and Back Again')
        ->and($results[0]->year)->toBe(1937)
        ->and($results[0]->posterPath)->toBe('https://assets.hardcover.app/edition/17456445/hobbit.jpg')
        // Hardcover rates 0–5; the app shows 0–10.
        ->and($results[0]->communityRating)->toBe(8.6)
        ->and($results[0]->authors)->toBe(['J.R.R. Tolkien'])
        ->and($results[1]->authors)->toBe(['Frank Herbert', 'Brian Herbert'])
        ->and($results[2]->ref)->toBe('999999')
        ->and($results[2]->title)->toBe('Coverless Placeholder')
        ->and($results[2]->posterPath)->toBeNull();
});

test('book details map hardcover fields to the shared shape', function () {
    enableBooksModule();
    fakeHardcover(['Details' => 'book']);

    $book = hardcoverProvider()->details('book', '2459845', 'en');

    expect($book->summary->title)->toBe('The Hobbit, or There and Back Again')
        ->and($book->summary->year)->toBe(1937)
        ->and($book->summary->communityRating)->toBe(8.6)
        ->and($book->tagline)->toBe('The journey through Middle-earth begins here')
        // Descriptions carry light HTML — stripped before display.
        ->and($book->overview)->toBe('"In a hole in the ground there lived a hobbit." So begins one of the most beloved tales.')
        ->and($book->genres)->toBe(['Fantasy', 'Classics'])
        ->and(array_map(fn ($keyword): array => $keyword->jsonSerialize(), $book->keywords))->toBe([
            ['name' => 'Adventurous', 'ref' => null],
            ['name' => 'Lighthearted', 'ref' => null],
        ])
        ->and($book->summary->authors)->toBe(['J.R.R. Tolkien'])
        ->and($book->extra['pages'])->toBe(320)
        ->and($book->summary->progressTotal)->toBe(320)
        ->and($book->extra['series'])->toBe(['name' => 'Middle Earth', 'position' => 1.0])
        ->and($book->extra['coverColor'])->toBe('#c58f27')
        ->and($book->extra['contentWarnings'])->toBe(['Violence'])
        ->and($book->externalLinks['hardcover'])->toBe('https://hardcover.app/books/the-hobbit')
        ->and($book->posters)->toBe(['https://assets.hardcover.app/edition/17456445/hobbit.jpg'])
        // Books have no runtime — pages are the progress unit, and page
        // totals must never leak into time statistics via runtime.
        ->and($book->runtime)->toBeNull();
});

test("a book's page links to the series Hardcover features on it", function () {
    enableBooksModule();
    fakeHardcover(['Details' => 'book']);

    expect(hardcoverProvider()->details('book', '2459845', 'en')->collection)->toEqual(new CollectionLink('127396', 'Middle Earth'));
});

test('a book without a place in its series links to no series', function () {
    enableBooksModule();
    $book = jsonFixture('hardcover/book');
    // A loose set (a reading list) numbers none of its books.
    $book['data']['books_by_pk']['cached_featured_series']['position'] = null;
    Http::fake(['api.hardcover.app/*' => Http::response($book)]);

    expect(hardcoverProvider()->details('book', '2459845', 'en')->collection)->toBeNull();
});

test('a series holds its main volumes in their numbering, companions left out', function () {
    enableBooksModule();
    fakeHardcover(['Series' => 'series']);

    $series = hardcoverProvider()->collection('127396', 'en');

    expect($series->name)->toBe('Middle Earth')
        ->and($series->overview)->toBe("Tolkien's legendarium.")
        // A prequel numbered 0 opens the series; the novella at #1.5 is a companion.
        ->and(array_map(fn ($part): string => $part->title, $series->parts))->toBe([
            'The Silmarillion',
            'The Hobbit, or There and Back Again',
            'The Fellowship of the Ring',
            'The Two Towers',
        ])
        ->and($series->parts[2]->posterPath)->toBeNull()
        ->and($series->posterPath)->toBe('https://assets.hardcover.app/edition/30893217/silmarillion.jpg');

    // One book per position, the duplicates (translations, omnibuses) left to Hardcover.
    Http::assertSent(fn (Request $request): bool => str_contains((string) data_get($request->data(), 'query'), 'distinct_on: position')
        && str_contains((string) data_get($request->data(), 'query'), 'compilation: {_eq: false}'));
});

test('a series of companions alone, or none at all, is no collection', function (array $answer) {
    enableBooksModule();
    Http::fake(['api.hardcover.app/*' => Http::response($answer)]);

    expect(fn () => hardcoverProvider()->collection('127396', 'en'))->toThrow(ItemNotFoundException::class);
})->with([
    'companions only' => [['data' => ['series_by_pk' => ['id' => 9, 'name' => 'Extras', 'description' => null, 'book_series' => [
        ['position' => 0.5, 'book' => ['id' => 1, 'title' => 'A novella']],
    ]]]]],
    'no such series' => [['data' => ['series_by_pk' => null]]],
]);

test('a series ref that is no number names no series', function () {
    enableBooksModule();
    Http::fake();

    expect(fn () => hardcoverProvider()->collection('harry-potter', 'en'))->toThrow(ItemNotFoundException::class);

    Http::assertNothingSent();
});

test('book details expose linkable authors carrying provider refs', function () {
    enableBooksModule();
    fakeHardcover(['Details' => 'book']);

    $links = hardcoverProvider()->details('book', '2459845', 'en')->extra['authorLinks'];

    expect($links)->toHaveCount(1)
        ->and($links[0]->name)->toBe('J.R.R. Tolkien')
        ->and($links[0]->kind)->toBe(CreatorKind::Person)
        ->and($links[0]->ref)->toBe('132049')
        ->and($links[0]->provider)->toBe('hardcover');
});

test("a book's illustrator, translator or narrator is credited in that part, not as its author", function () {
    enableBooksModule();
    fakeHardcover(['Details' => 'book']);

    $book = hardcoverProvider()->details('book', '2459845', 'en');

    expect($book->summary->authors)->toBe(['J.R.R. Tolkien'])
        ->and(array_map(fn ($link) => $link->name, $book->extra['authorLinks']))->toBe(['J.R.R. Tolkien'])
        ->and($book->crew)->toHaveCount(1)
        ->and($book->crew[0]->name)->toBe('Alan Lee')
        ->and($book->crew[0]->personRef)->toBe('205738')
        ->and($book->crew[0]->department)->toBe(Department::Art)
        ->and($book->crew[0]->role)->toBe(BookRole::Illustrator->value);
});

test("a book's page carries its ISBN and Goodreads page", function () {
    enableBooksModule();
    fakeHardcover(['Details' => 'book']);

    $book = hardcoverProvider()->details('book', '2459845', 'en');

    // The printed edition's ISBN wins over the e-book's.
    expect($book->extra['isbn'])->toBe('9780618260300')
        ->and($book->extra['nonfiction'])->toBeFalse()
        ->and($book->externalLinks['goodreads'])->toBe('https://www.goodreads.com/book/show/3686567')
        ->and($book->extra['goodreadsId'])->toBe('3686567')
        // A plain book wears no kind badge.
        ->and($book->summary->extra)->not->toHaveKey('bookKind');
});

test("a book that is not a plain book says what it is, and an e-book's ISBN stands in for a missing printed one", function () {
    enableBooksModule();
    $answer = jsonFixture('hardcover/book');
    $answer['data']['books_by_pk'] = [
        'book_category_id' => HardcoverBookKind::GraphicNovel->value,
        'literary_type_id' => HardcoverLiteraryType::Nonfiction->value,
        'default_physical_edition' => ['isbn_13' => null, 'isbn_10' => null],
        'default_ebook_edition' => ['isbn_13' => null, 'isbn_10' => '0547951965'],
        'book_mappings' => [],
    ] + $answer['data']['books_by_pk'];
    Http::fake(['api.hardcover.app/*' => Http::response($answer)]);

    $book = hardcoverProvider()->details('book', '2459845', 'en');

    expect($book->summary->extra['bookKind'])->toBe(BookKind::GraphicNovel->value)
        ->and($book->extra['nonfiction'])->toBeTrue()
        ->and($book->extra['isbn'])->toBe('9780547951966')
        ->and($book->externalLinks)->not->toHaveKey('goodreads')
        ->and($book->extra['goodreadsId'])->toBeNull();
});

test('a reader of another language sees the book as their most read edition names it, with its translator', function () {
    enableBooksModule();
    fakeHardcover(['Details' => 'book']);

    $book = hardcoverProvider()->details('book', '2459845', 'pl');

    expect($book->summary->title)->toBe('Hobbit, czyli tam i z powrotem')
        ->and($book->summary->posterPath)->toBe('https://assets.hardcover.app/edition/31415926/hobbit-pl.jpg')
        ->and($book->extra['coverColor'])->toBe('#5b7c3a')
        ->and($book->extra['originalTitle'])->toBe('The Hobbit, or There and Back Again')
        ->and($book->extra['localEdition'])->toBe(['publisher' => 'Iskry', 'year' => 2012])
        // The edition's translator; the book's illustrator stays.
        ->and(array_map(fn ($credit) => [$credit->name, $credit->role], $book->crew))->toBe([
            ['Alan Lee', BookRole::Illustrator->value],
            ['Maria Skibniewska', BookRole::Translator->value],
        ])
        ->and($book->localizedTitles['pl']->title)->toBe('Hobbit, czyli tam i z powrotem')
        ->and($book->localizedTitles['en']->title)->toBe('The Hobbit, or There and Back Again')
        ->and($book->localizedTitles['en']->posterPath)->toBe('https://assets.hardcover.app/edition/17456445/hobbit.jpg');
    // The fallback language reads the book itself: only the other UI languages are asked for, as variables.
    Http::assertSent(fn (Request $request): bool => in_array('pl', (array) data_get($request->data(), 'variables'), true)
        && ! in_array('en', (array) data_get($request->data(), 'variables'), true));
});

test('a reader of the fallback language sees the book itself; an edition without a cover keeps the book\'s, one dated as the book has no year of its own', function () {
    enableBooksModule();
    $answer = jsonFixture('hardcover/book');
    $answer['data']['books_by_pk']['edition0'][0]['cached_image'] = [];
    // Hardcover often copies the book's date onto its editions.
    $answer['data']['books_by_pk']['edition0'][0]['release_date'] = '1937-09-21';
    $answer['data']['books_by_pk']['edition0'][0]['release_year'] = null;
    Http::fake(['api.hardcover.app/*' => Http::response($answer)]);

    $english = hardcoverProvider()->details('book', '2459845', 'en');
    $polish = hardcoverProvider()->details('book', '2459845', 'pl');

    expect($english->summary->title)->toBe('The Hobbit, or There and Back Again')
        ->and($english->extra['originalTitle'])->toBeNull()
        ->and($english->extra['localEdition'])->toBeNull()
        ->and(array_map(fn ($credit) => $credit->name, $english->crew))->toBe(['Alan Lee'])
        ->and($polish->summary->title)->toBe('Hobbit, czyli tam i z powrotem')
        ->and($polish->summary->posterPath)->toBe('https://assets.hardcover.app/edition/17456445/hobbit.jpg')
        ->and($polish->localizedTitles['pl']->posterPath)->toBe('https://assets.hardcover.app/edition/17456445/hobbit.jpg')
        ->and($polish->extra['localEdition'])->toBe(['publisher' => 'Iskry', 'year' => null]);
});

test('a stored book reads in each language as its reader knows it, whichever language stored it', function () {
    enableBooksModule();
    fakeHardcover(['Details' => 'book']);

    $book = app(TitleStore::class)->persist('book', '2459845', 'pl');

    expect($book->titleIn('pl'))->toBe('Hobbit, czyli tam i z powrotem')
        ->and($book->posterIn('pl'))->toBe('https://assets.hardcover.app/edition/31415926/hobbit-pl.jpg')
        ->and($book->titleIn('en'))->toBe('The Hobbit, or There and Back Again')
        ->and($book->posterIn('en'))->toBe('https://assets.hardcover.app/edition/17456445/hobbit.jpg');
});

test('a Goodreads id leads to the book Hardcover maps it to, and an unmapped one to none', function () {
    enableBooksModule();
    Http::fake(['api.hardcover.app/*' => fn (Request $request) => Http::response(['data' => [
        'book_mappings' => data_get($request->data(), 'variables.id') === '3686567' ? [['book_id' => 2459845]] : [],
    ]])]);

    expect(hardcoverProvider()->externalIdSources())->toBe([ExternalIdSource::Goodreads])
        ->and(hardcoverProvider()->refForExternalId('book', ExternalIdSource::Goodreads, '3686567'))->toBe('2459845')
        ->and(hardcoverProvider()->refForExternalId('book', ExternalIdSource::Goodreads, '1'))->toBeNull()
        ->and(hardcoverProvider()->refForExternalId('book', ExternalIdSource::Isbn, '9780618260300'))->toBeNull();
});

test('the author page maps hardcover fields and books as credits', function () {
    enableBooksModule();
    fakeHardcover(['Author' => 'author']);

    $person = hardcoverProvider()->person('132049', 'en');
    $creditRefs = array_map(fn ($credit) => $credit->media->ref, $person->credits);

    expect($person->ref)->toBe('132049')
        ->and($person->name)->toBe('J.R.R. Tolkien')
        // Bios carry light HTML — stripped before display.
        ->and($person->biography)->toStartWith('John Ronald Reuel Tolkien was an English writer')
        ->and($person->birthday)->toBe('1892-01-03')
        ->and($person->deathday)->toBe('1973-09-02')
        ->and($person->profilePath)->toBe('https://assets.hardcover.app/authors/132049/tolkien.jpg')
        ->and($person->externalLinks['hardcover'])->toBe('https://hardcover.app/authors/j-r-r-tolkien')
        ->and($person->credits)->toHaveCount(4)
        // Sorted by readers: The Fellowship (9120) before The Hobbit (7400).
        ->and($person->credits[0]->media->type)->toBe('book')
        ->and($person->credits[0]->media->title)->toBe('The Fellowship of the Ring')
        ->and($person->credits[0]->department)->toBe(Department::Author)
        ->and($person->credits[0]->popularity)->toBe(9120.0)
        ->and($person->credits[3]->media->title)->toBe('Coverless Placeholder')
        ->and($person->credits[3]->media->posterPath)->toBeNull()
        // The null row is still rejected.
        ->and($creditRefs)->toBe(['312460', '2459845', '431002', '999999']);
});

test("an author's page says where they come from, links their own site and Wikipedia, and shelves a translation under translation", function () {
    enableBooksModule();
    fakeHardcover(['Author' => 'author']);

    $person = hardcoverProvider()->person('132049', 'en');

    expect($person->origin)->toBe('United Kingdom')
        ->and($person->placeOfBirth)->toBeNull()
        // The Estate's site is the official one; the society and the fan site stay off.
        ->and($person->externalLinks)->toBe([
            'homepage' => 'https://www.tolkienestate.com/',
            'wikipedia' => 'https://en.wikipedia.org/wiki/J._R._R._Tolkien',
            'hardcover' => 'https://hardcover.app/authors/j-r-r-tolkien',
        ])
        ->and($person->credits[2]->media->title)->toBe('Sir Gawain and the Green Knight')
        ->and($person->credits[2]->department)->toBe(Department::Translation)
        // Credited as author and illustrator of The Hobbit: it stays among what he wrote.
        ->and($person->credits[1]->media->title)->toBe('The Hobbit, or There and Back Again')
        ->and($person->credits[1]->department)->toBe(Department::Author)
        // Most of the shelf is written, so the page opens on it.
        ->and($person->knownForDepartment)->toBe(Department::Author);
});

test("an author's own site may be titled a bare Website, and a host that only ends like Wikipedia's is not it", function () {
    enableBooksModule();
    $answer = jsonFixture('hardcover/author');
    $answer['data']['authors_by_pk']['links'] = [
        ['url' => 'https://notwikipedia.org/wiki/Blake_Crouch', 'title' => 'Wiki mirror'],
        ['url' => 'http://www.blakecrouch.com/', 'title' => 'Website', 'social_type' => 'website'],
    ];
    Http::fake(['api.hardcover.app/*' => Http::response($answer)]);

    expect(hardcoverProvider()->person('132049', 'en')->externalLinks)->toBe([
        'homepage' => 'http://www.blakecrouch.com/',
        'hardcover' => 'https://hardcover.app/authors/j-r-r-tolkien',
    ]);
});

test('an unknown author ref raises the not-found exception', function () {
    enableBooksModule();
    fakeHardcover([]);

    expect(fn () => hardcoverProvider()->person('424242', 'en'))
        ->toThrow(ItemNotFoundException::class);
});

test('unknown book refs raise the not-found exception', function () {
    enableBooksModule();
    fakeHardcover([]);

    expect(fn () => hardcoverProvider()->details('book', '424242', 'en'))
        ->toThrow(ItemNotFoundException::class);
});

test('trending restores the ranking order and skips unknown ids', function () {
    enableBooksModule();
    fakeHardcover(['BooksByIds' => 'books', 'Trending' => 'trending']);

    $trending = hardcoverProvider()->trending('en');

    // Ranking: 312460 first, 2459845 second; 424242 has no books row.
    expect($trending)->toHaveCount(2)
        ->and($trending[0]->ref)->toBe('312460')
        ->and($trending[1]->ref)->toBe('2459845');
});

test("similar books keep Hardcover's order, the book itself and unknown ids left out", function () {
    enableBooksModule();
    fakeHardcover(['Details' => 'book', 'BooksByIds' => 'books']);

    $similar = hardcoverProvider()->similarTo('book', '2459845', 'en');

    expect(array_map(fn ($book) => $book->ref, $similar))->toBe(['312460']);
    Http::assertSent(fn (Request $request): bool => str_contains((string) data_get($request->data(), 'query'), 'query BooksByIds')
        && data_get($request->data(), 'variables.ids') === [312460, 424242]);
});

test("a book's similar list rides on its details: the page asks once, the cards once more", function () {
    enableBooksModule();
    fakeHardcover(['Details' => 'book', 'BooksByIds' => 'books']);

    hardcoverProvider()->details('book', '2459845', 'en');
    Http::assertSentCount(1);

    hardcoverProvider()->similarTo('book', '2459845', 'en');
    Http::assertSentCount(2);
});

test('a book with no similar list asks for no cards', function () {
    enableBooksModule();
    $book = jsonFixture('hardcover/book');
    $book['data']['books_by_pk']['cached_similar_book_ids'] = [];
    Http::fake(['api.hardcover.app/*' => Http::response($book)]);

    expect(hardcoverProvider()->similarTo('book', '2459845', 'en'))->toBe([]);
    Http::assertSentCount(1);
});

test('discover listings serve popular, upcoming, genres and genre pages', function () {
    enableBooksModule();
    fakeHardcover([
        'Popular' => 'books',
        'Upcoming' => 'books',
        'Genres' => 'genres',
        'ByGenre' => 'search',
    ]);

    $provider = hardcoverProvider();

    expect($provider->discoverableTypes())->toBe(['book'])
        ->and($provider->popular('book', 'en', MatureContent::Suggestive))->toHaveCount(2)
        ->and($provider->upcoming('book', 'en', MatureContent::Suggestive))->toHaveCount(2)
        // Genre refs ARE the display names — the search index filters by name.
        ->and($provider->genres('book', 'en'))->toEqual([new GenreOption('Fantasy', 'Fantasy'), new GenreOption('Fiction', 'Fiction')]);

    $listing = $provider->byGenre('book', 'Fantasy', 'en', MatureContent::Suggestive, 2);
    $listingRefs = array_map(fn ($item) => $item->ref, $listing->items);

    expect($listing->page)->toBe(2)
        ->and($listing->totalPages)->toBe(3)
        ->and($listing->items)->toHaveCount(2)
        // The same coverless Typesense document included by search is
        // deliberately excluded from this curated Discover shelf.
        ->and($listingRefs)->toBe(['2459845', '312460']);
});
