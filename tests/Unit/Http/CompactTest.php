<?php

use App\Http\Mcp\Compact;

test('pictures, nulls, falses and empty lists go; zeros and links stay', function () {
    expect(Compact::of([
        'title' => 'Dune',
        'posterPath' => '/dune.jpg',
        'posterUrl' => 'https://images.example.com/dune.jpg',
        'liveStreamUrl' => 'https://stream.example.com',
        'overview' => null,
        'favorite' => false,
        'listIds' => [],
        'remainingEpisodes' => 0,
        'watched' => true,
    ]))->toBe([
        'title' => 'Dune',
        'liveStreamUrl' => 'https://stream.example.com',
        'remainingEpisodes' => 0,
        'watched' => true,
    ]);
});

test('nested answers are cut the same way, and a list stays a list', function () {
    expect(Compact::of(['data' => [
        ['ref' => '1', 'state' => ['rating' => null, 'plays' => 2]],
        ['ref' => '2', 'state' => ['rating' => null]],
    ]]))->toBe(['data' => [
        ['ref' => '1', 'state' => ['plays' => 2]],
        ['ref' => '2'],
    ]]);
});

test('a scalar passes through', function () {
    expect(Compact::of('text'))->toBe('text');
});

test('what only draws a card goes, and so does a value nobody touched', function () {
    expect(Compact::of(['state' => [
        'plays' => 0, 'cycle' => 1, 'completedCycles' => 0, 'skippedEpisodes' => 0,
        'progression' => true, 'followSource' => 'activity', 'position' => 3,
        'remainingMinutes' => 120, 'watchedMinutes' => 60,
        'remainingEpisodes' => 0, 'watchedEpisodes' => 2,
    ]]))->toBe(['state' => ['remainingEpisodes' => 0, 'watchedEpisodes' => 2]]);

    // A rewatch is news: the second cycle stays.
    expect(Compact::of(['cycle' => 2, 'plays' => 3]))->toBe(['cycle' => 2, 'plays' => 3]);
});

test("a written title's own state is said once, on its card", function () {
    $card = ['type' => 'episode', 'ref' => '1:1:1', 'state' => ['watched' => true]];

    expect(Compact::withoutRepeatedState([
        'data' => $card,
        'states' => ['episode:1:1:1' => ['watched' => true], 'season:1:1' => ['watched' => true], 'show:1' => []],
    ]))->toBe([
        'data' => $card,
        'states' => ['season:1:1' => ['watched' => true], 'show:1' => []],
    ]);
});

test('an answer that is not one card with its state keeps its states', function (array $answer) {
    expect(Compact::withoutRepeatedState($answer))->toBe($answer);
})->with([
    'a list of titles' => [['data' => [['type' => 'movie', 'ref' => '1', 'outcome' => 'added']], 'states' => ['movie:1' => ['listIds' => [3]]]]],
    'a card without a state' => [['data' => ['type' => 'movie', 'ref' => '1'], 'states' => ['movie:1' => []]]],
    'no states' => [['data' => ['type' => 'movie', 'ref' => '1', 'state' => []]]],
]);

test('a card with a state but no ref is a broken answer', function () {
    Compact::withoutRepeatedState(['data' => ['type' => 'movie', 'state' => []], 'states' => []]);
})->throws(UnexpectedValueException::class);
