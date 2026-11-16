<?php

use App\Accounts\Username;

test('a wanted name is made to fit the username rule', function (string $wanted, ?string $username) {
    expect(Username::from($wanted))->toBe($username);
})->with([
    'already fits' => ['alex', 'alex'],
    'capitals and a hyphen' => ['Alex-GH', 'alex_gh'],
    'letters with diacritics' => ['Łukasz', 'lukasz'],
    'a dot dropped' => ['alex.example', 'alexexample'],
    'room left for a number' => [str_repeat('a', 40), str_repeat('a', Username::MAX - Username::SUFFIX_ROOM)],
    'too little left' => ['a.', null],
    'nothing left' => ['***', null],
]);
