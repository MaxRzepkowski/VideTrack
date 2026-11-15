<?php

use App\Catalog\Data\MediaSummary;
use App\Enums\SortDirection;
use App\Suggestions\Data\ShelfQuery;
use App\Suggestions\Data\Suggestion;
use App\Suggestions\Data\SuggestionGroup;
use App\Suggestions\ShelfArranger;
use App\Suggestions\ShelfSort;

function suggested(string $title, array $with = [], array $origins = [], ?string $addedOn = null): Suggestion
{
    return new Suggestion(new MediaSummary(...['type' => 'movie', 'ref' => $title, 'provider' => 'tmdb', 'title' => $title, ...$with]), [], $origins, $addedOn);
}

/**
 * @param  list<Suggestion>  $suggestions
 * @return list<string>
 */
function titlesOf(array $suggestions): array
{
    return array_map(fn (Suggestion $suggestion): string => $suggestion->media->title, $suggestions);
}

test('a title passes only when it passes every filter set', function (ShelfQuery $query, bool $admitted) {
    $title = suggested('The Thing', ['year' => 1982], ['listed']);

    expect($query->admits($title))->toBe($admitted);
})->with([
    'nothing set' => [new ShelfQuery, true],
    'its type' => [new ShelfQuery(type: 'movie'), true],
    'another type' => [new ShelfQuery(type: 'show'), false],
    'a way in it came by' => [new ShelfQuery(origin: 'listed'), true],
    'a way in it did not come by' => [new ShelfQuery(origin: 'followed'), false],
    'its decade' => [new ShelfQuery(decade: 1980), true],
    'another decade' => [new ShelfQuery(decade: 1990), false],
    'part of its name, whatever the case' => [new ShelfQuery(q: 'thin'), true],
    'not in its name' => [new ShelfQuery(q: 'alien'), false],
    'one filter failing among those passing' => [new ShelfQuery(type: 'movie', decade: 1990), false],
]);

test('a title without a year is in no decade', function () {
    expect((new ShelfQuery(decade: 1980))->admits(suggested('Undated')))->toBeFalse();
});

test('a group left with nothing is dropped, and a search naming a subject keeps all of it', function () {
    $groups = [
        new SuggestionGroup([suggested('Aliens', ['year' => 1986]), suggested('Alien 3', ['year' => 1992])], key: 'a', title: 'Alien Collection'),
        new SuggestionGroup([suggested('Predator 2', ['year' => 1990])], key: 'p', title: 'Predator Collection'),
    ];
    $arranger = new ShelfArranger;

    $eighties = $arranger->narrow($groups, new ShelfQuery(decade: 1980));
    $named = $arranger->narrow($groups, new ShelfQuery(q: 'predator'));
    $byTitle = $arranger->narrow($groups, new ShelfQuery(q: 'aliens'));

    expect(array_map(fn (SuggestionGroup $group): array => [$group->key, titlesOf($group->suggestions)], $eighties))->toBe([['a', ['Aliens']]])
        ->and(array_map(fn (SuggestionGroup $group): array => [$group->key, titlesOf($group->suggestions)], $named))->toBe([['p', ['Predator 2']]])
        ->and(array_map(fn (SuggestionGroup $group): array => [$group->key, titlesOf($group->suggestions)], $byTitle))->toBe([['a', ['Aliens']]]);
});

test('titles keep the source\'s order unless another is asked for, and any order reads backwards', function (ShelfSort $sort, SortDirection $dir, array $expected) {
    $titles = [
        suggested('Bee', ['releaseDate' => '2001-01-01', 'communityRating' => 6.0], addedOn: '2020-03-01'),
        suggested('ant', ['releaseDate' => '2003-01-01', 'communityRating' => 8.0], addedOn: '2020-01-01'),
        suggested('Cat', ['releaseDate' => '2002-01-01', 'communityRating' => 7.0], addedOn: '2020-02-01'),
    ];

    expect(titlesOf((new ShelfArranger)->sortTitles($titles, $sort, $dir)))->toBe($expected);
})->with([
    'the source\'s own' => [ShelfSort::Suggested, SortDirection::Asc, ['Bee', 'ant', 'Cat']],
    'the source\'s own, backwards' => [ShelfSort::Suggested, SortDirection::Desc, ['Cat', 'ant', 'Bee']],
    'oldest release' => [ShelfSort::Release, SortDirection::Asc, ['Bee', 'Cat', 'ant']],
    'newest release' => [ShelfSort::Release, SortDirection::Desc, ['ant', 'Cat', 'Bee']],
    'added longest ago' => [ShelfSort::Added, SortDirection::Asc, ['ant', 'Cat', 'Bee']],
    'best rated' => [ShelfSort::Rating, SortDirection::Desc, ['ant', 'Cat', 'Bee']],
    'by name, whatever the case' => [ShelfSort::Title, SortDirection::Asc, ['ant', 'Bee', 'Cat']],
    'an order subjects have and titles do not' => [ShelfSort::Remaining, SortDirection::Asc, ['Bee', 'ant', 'Cat']],
]);

test('subjects sort by what is left, how far through, their newest title and their name', function (ShelfSort $sort, SortDirection $dir, array $expected) {
    $subjects = [
        new SuggestionGroup([suggested('a', ['releaseDate' => '2001-01-01'])], key: 'one', title: 'One left', finished: 1, total: 2),
        new SuggestionGroup([suggested('b', ['releaseDate' => '2024-01-01']), suggested('c', ['releaseDate' => '2010-01-01']), suggested('d')], key: 'three', title: 'Three left', finished: 1, total: 4),
        new SuggestionGroup([suggested('e', ['releaseDate' => '1985-01-01'])], key: 'deep', title: 'Deep in', finished: 2, total: 3),
    ];

    expect(array_map(fn (SuggestionGroup $group) => $group->key, (new ShelfArranger)->sortSubjects($subjects, $sort, $dir)))->toBe($expected);
})->with([
    'the source\'s own' => [ShelfSort::Suggested, SortDirection::Asc, ['one', 'three', 'deep']],
    'most left' => [ShelfSort::Remaining, SortDirection::Desc, ['three', 'deep', 'one']],
    'furthest through' => [ShelfSort::Progress, SortDirection::Desc, ['deep', 'one', 'three']],
    'newest missing title' => [ShelfSort::Release, SortDirection::Desc, ['three', 'one', 'deep']],
    'by name' => [ShelfSort::Title, SortDirection::Asc, ['deep', 'one', 'three']],
]);
