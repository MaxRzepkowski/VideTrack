<?php

use App\Catalog\Data\MediaSummary;
use App\Catalog\Matching;

test('titles match whatever their case, spacing and punctuation', function (string $a, string $b, bool $same) {
    expect(Matching::sameTitle($a, $b))->toBe($same);
})->with([
    'punctuation' => ['Resident Evil 7: Biohazard', 'Resident Evil 7 - biohazard', true],
    'digits count' => ['Resident Evil 7', 'Resident Evil 8', false],
    'empty never matches' => ['', '!!', false],
]);

test('authors match on surname and, where both give one, the first initial', function (string $a, string $b, bool $same) {
    expect(Matching::sameAuthor($a, $b))->toBe($same);
})->with([
    'initials vs full name' => ['J. K. Rowling', 'Joanne Rowling', true],
    'initials spacing' => ['J.K. Rowling', 'J. K. Rowling', true],
    'surname alone' => ['Rowling', 'J.K. Rowling', true],
    'same surname, other person' => ['Stephen King', 'Owen King', false],
    'other surname' => ['Brian McClellan', 'Brian Sanderson', false],
    'diacritics kept' => ['Gabriel García Márquez', 'G. García Márquez', true],
]);

test('a record shares an author when any credit matches any wanted name', function () {
    $book = new MediaSummary(type: 'book', ref: '1', provider: 'hardcover', title: 'Promise of Blood', authors: ['Brian McClellan']);

    expect(Matching::sharesAuthor(['McClellan'], $book))->toBeTrue()
        ->and(Matching::sharesAuthor(['Brandon Sanderson'], $book))->toBeFalse()
        ->and(Matching::sharesAuthor(['McClellan'], new MediaSummary(type: 'book', ref: '2', provider: 'hardcover', title: 'x')))->toBeFalse();
});

test('a record goes by its title or any other name its provider knows it by', function () {
    $summary = new MediaSummary(type: 'game', ref: '1', provider: 'igdb', title: 'Bear & Breakfast', alternativeTitles: ['Bear and Breakfast']);

    expect(Matching::namesTitle($summary, 'Bear and Breakfast'))->toBeTrue()
        ->and(Matching::namesTitle($summary, 'bear & breakfast'))->toBeTrue()
        ->and(Matching::namesTitle($summary, 'Bear Breakfast Deluxe'))->toBeFalse();
});

test('a game name comes without what a store or a launcher adds to it', function (string $name, string $base) {
    expect(Matching::gameBase($name))->toBe($base);
})->with([
    'edition after a dash, platform in brackets, trademark' => ['The Witcher® 3: Wild Hunt – Game of the Year Edition (PC)', 'The Witcher 3: Wild Hunt'],
    'GOTY after a colon' => ['Fallout 4: GOTY', 'Fallout 4'],
    'edition after a space' => ['Final Fantasy XV Royal Definitive Edition', 'Final Fantasy XV Royal'],
    'year in brackets' => ['DOOM (2016)', 'DOOM'],
    'director\'s cut' => ["Death Stranding Director's Cut", 'Death Stranding'],
    'ampersand' => ['Bear & Breakfast', 'Bear and Breakfast'],
    'a name with nothing added' => ['Hades', 'Hades'],
    'a name made of an edition alone stays' => ['GOTY', 'GOTY'],
    'an edition word inside the name stays' => ['Ultimate Chicken Horse', 'Ultimate Chicken Horse'],
]);

test('a record is the game a launcher names once editions are set aside on both sides', function () {
    $witcher = new MediaSummary(type: 'game', ref: '1942', provider: 'igdb', title: 'The Witcher 3: Wild Hunt');
    $knight = new MediaSummary(type: 'game', ref: '14593', provider: 'igdb', title: 'Hollow Knight');

    expect(Matching::namesGame($witcher, 'The Witcher 3: Wild Hunt - Complete Edition'))->toBeTrue()
        ->and(Matching::namesGame($witcher, 'The Witcher 2: Assassins of Kings'))->toBeFalse()
        ->and(Matching::namesGame($knight, 'Hollow'))->toBeFalse();
});
