<?php

use App\Integrations\Steam\SteamProfile;

test('a profile is read from whatever the user pasted', function (string $typed, string $value, bool $isCustomName) {
    $profile = SteamProfile::parse($typed);

    expect($profile?->value)->toBe($value)
        ->and($profile?->isCustomName)->toBe($isCustomName);
})->with([
    'an id' => ['76561197960287930', '76561197960287930', false],
    'a profiles address' => ['https://steamcommunity.com/profiles/76561197960287930/', '76561197960287930', false],
    'a custom address' => ['steamcommunity.com/id/gabelogannewell', 'gabelogannewell', true],
    'a bare name with spaces around' => ['  gabe_newell  ', 'gabe_newell', true],
]);

test('what cannot be a profile is refused before Steam is asked', function (string $typed) {
    expect(SteamProfile::parse($typed))->toBeNull();
})->with(['', 'a', 'gabe newell', 'https://steamcommunity.com/id/', str_repeat('x', 33)]);

test('one profile is one memo key, however it was typed', function () {
    expect(SteamProfile::parse('76561197960287930')?->key())->toBe(SteamProfile::parse('https://steamcommunity.com/profiles/76561197960287930')?->key())
        ->and(SteamProfile::parse('gabe')?->key())->not->toBe(SteamProfile::parse('76561197960287930')?->key());
});
