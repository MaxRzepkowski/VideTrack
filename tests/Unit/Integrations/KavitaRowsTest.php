<?php

use App\Catalog\MediaKind;
use App\Enums\MediaUserStatus;
use App\Integrations\Import\Rows\MediaRow;
use App\Integrations\Import\Rows\SkipRow;
use App\Integrations\Kavita\KavitaLibraryType;
use App\Integrations\Kavita\KavitaPublicationStatus;
use App\Integrations\Kavita\KavitaRows;
use App\Library\PlayDating;
use Tests\TestCase;

uses(TestCase::class);

/**
 * @param  array<string, mixed>  $series
 * @return array<string, mixed>
 */
function kavitaSeries(array $series = []): array
{
    return ['id' => 1, 'name' => 'Monster', 'localizedName' => 'Monster', 'pages' => 100, 'pagesRead' => 100, 'latestReadDate' => '2026-09-20T21:20:00', 'hasUserRated' => false, 'userRating' => 0, 'malId' => 0, 'hardcoverId' => 0, 'comicVineId' => null, ...$series];
}

function kavitaRow(array $series, MediaKind $kind, bool $over = false): MediaRow
{
    $row = (new KavitaRows)->row($series, $kind, $over);

    return $row instanceof MediaRow ? $row : throw new LogicException('Expected a media row.');
}

test('a library type is what its series are here; light novels are books, images nothing', function (KavitaLibraryType $type, ?MediaKind $kind) {
    expect($type->kind())->toBe($kind);
})->with([
    [KavitaLibraryType::Manga, MediaKind::Manga],
    [KavitaLibraryType::Comic, MediaKind::Comic],
    [KavitaLibraryType::ComicVine, MediaKind::Comic],
    [KavitaLibraryType::Book, MediaKind::Book],
    [KavitaLibraryType::LightNovel, MediaKind::Book],
    [KavitaLibraryType::Image, null],
]);

test('a manga or comic read through is finished only when its publication is over', function (MediaKind $kind, bool $over) {
    $row = kavitaRow(kavitaSeries(), $kind, $over);

    expect([$row->plays !== [], $row->status?->status])->toBe([$over, $over ? null : MediaUserStatus::InProgress])
        ->and(kavitaRow(kavitaSeries(['pagesRead' => 10]), $kind, true)->plays)->toBe([]);
})->with([
    'manga, over' => [MediaKind::Manga, true],
    'manga, still coming out' => [MediaKind::Manga, false],
    'comic, over' => [MediaKind::Comic, true],
    'comic, still coming out' => [MediaKind::Comic, false],
]);

test('a publication is over once completed, cancelled or ended', function () {
    expect(KavitaPublicationStatus::over())->toBe([KavitaPublicationStatus::Completed, KavitaPublicationStatus::Cancelled, KavitaPublicationStatus::Ended])
        ->and(KavitaPublicationStatus::overList())->toBe('2,3,4');
});

test('a series with its catalogue id is told from one placed by its title', function () {
    $rows = new KavitaRows;

    expect($rows->knowsCatalogueId(kavitaSeries(['malId' => 1]), MediaKind::Manga))->toBeTrue()
        ->and($rows->knowsCatalogueId(kavitaSeries(['malId' => 1]), MediaKind::Book))->toBeFalse()
        ->and($rows->knowsCatalogueId(kavitaSeries(['comicVineId' => '4050-45228']), MediaKind::Comic))->toBeTrue()
        ->and($rows->knowsCatalogueId(kavitaSeries(), MediaKind::Manga))->toBeFalse();
});

test('a book read through is finished whatever its publication; one not read through is not', function () {
    expect(kavitaRow(kavitaSeries(), MediaKind::Book)->plays)->toHaveCount(1)
        ->and(kavitaRow(kavitaSeries(['pagesRead' => 50]), MediaKind::Book, true)->plays)->toBe([]);
});

test('a finished series is read as of its last reading, a date that moves on', function () {
    $play = kavitaRow(kavitaSeries(), MediaKind::Book)->plays[0];

    expect([$play->at?->toDateString(), $play->dating])->toBe(['2026-09-20', PlayDating::Activity]);
});

test('a series begun is in progress with no counter; one never opened says nothing', function () {
    $begun = kavitaRow(kavitaSeries(['pagesRead' => 30]), MediaKind::Manga);

    expect([$begun->plays, $begun->status?->status, $begun->status?->progress])->toBe([[], MediaUserStatus::InProgress, null])
        ->and((new KavitaRows)->row(kavitaSeries(['pagesRead' => 0]), MediaKind::Manga, false))->toBeNull();
});

test('a series is placed by its type\'s catalogue id, else by its exact title', function () {
    $manga = kavitaRow(kavitaSeries(['malId' => 1]), MediaKind::Manga);
    $comic = kavitaRow(kavitaSeries(['comicVineId' => '4050-45228']), MediaKind::Comic);
    $bareComic = kavitaRow(kavitaSeries(['comicVineId' => '45228']), MediaKind::Comic);
    $book = kavitaRow(kavitaSeries(['hardcoverId' => 312460]), MediaKind::Book);
    $byTitle = kavitaRow(kavitaSeries(['localizedName' => '', 'name' => 'Spice and Wolf']), MediaKind::Book);

    expect([$manga->subject->provider, $manga->subject->ref])->toBe(['mal', '1'])
        ->and([$comic->subject->provider, $comic->subject->ref, $bareComic->subject->ref])->toBe(['comicvine', '45228', '45228'])
        ->and([$book->subject->provider, $book->subject->ref])->toBe(['hardcover', '312460'])
        ->and([$byTitle->subject->byTitle?->title, $byTitle->subject->byTitle?->exactTitle])->toBe(['Spice and Wolf', true]);
});

test('a rating in stars comes over on our scale, only when the user rated', function () {
    expect(kavitaRow(kavitaSeries(['hasUserRated' => true, 'userRating' => 4.5]), MediaKind::Book)->rating?->value)->toBe(9)
        ->and(kavitaRow(kavitaSeries(['userRating' => 4.5]), MediaKind::Book)->rating)->toBeNull();
});

test('a series without a title says so', function () {
    expect((new KavitaRows)->row(kavitaSeries(['name' => '', 'localizedName' => '']), MediaKind::Manga, false))->toBeInstanceOf(SkipRow::class);
});
