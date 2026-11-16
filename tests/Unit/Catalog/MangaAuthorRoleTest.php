<?php

use App\Catalog\Providers\Mal\MangaAuthorRole;

test('MAL\'s role texts name the parts an author did, and a text it has not been seen to send names none', function (?string $text, array $parts) {
    expect(MangaAuthorRole::fromMal($text))->toBe($parts);
})->with([
    'the story' => ['Story', [MangaAuthorRole::Story]],
    'the art' => ['Art', [MangaAuthorRole::Art]],
    'one mangaka doing everything' => ['Story & Art', [MangaAuthorRole::Story, MangaAuthorRole::Art]],
    'a part MAL adds later' => ['Supervision', []],
    'no role at all' => [null, []],
]);
