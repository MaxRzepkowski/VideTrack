<?php

use App\Integrations\RetroAchievements\RetroAchievementsTitle;

test('a title reads back into the name a catalogue knows', function (string $title, ?string $searchable) {
    expect(RetroAchievementsTitle::searchable($title))->toBe($searchable);
})->with([
    'plain' => ['Super Mario Bros.', 'Super Mario Bros.'],
    'an article filed at the end' => ['Legend of Zelda, The', 'The Legend of Zelda'],
    'an article before a subtitle' => ['Legend of Zelda, The: A Link to the Past', 'The Legend of Zelda: A Link to the Past'],
    'an article before a dash' => ['Adventures of Lolo, The - Revenge', 'The Adventures of Lolo - Revenge'],
    'an article inside the title stays' => ['Batman, Robin and A Joker', 'Batman, Robin and A Joker'],
    'alternative names' => ['Pokemon Red Version | Pocket Monsters Red', 'Pokemon Red Version'],
    'a region, left to the matcher' => ['Castlevania: Dracula X (Japan)', 'Castlevania: Dracula X (Japan)'],
    'an unlicensed release' => ['~Unlicensed~ Hi-Leg Fantasy', 'Hi-Leg Fantasy'],
    'a prototype' => ['~Prototype~ Sonic the Hedgehog 2', 'Sonic the Hedgehog 2'],
    'a hack' => ['~Hack~ Plumber For All Seasons, A', null],
    'homebrew' => ['~Homebrew~ No Place To Hide', null],
    'a subset' => ['Mega Man: Powered Up [Subset - 468 Stages]', null],
    'nothing left' => ['~Unlicensed~ ', null],
]);
