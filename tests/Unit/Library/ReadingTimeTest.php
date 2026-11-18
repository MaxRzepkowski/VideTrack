<?php

use App\Accounts\Preferences\UserPreferences;
use App\Catalog\ProgressUnit;
use App\Library\ReadingTime;

test('what was read turns into minutes at the reader\'s own pace', function () {
    $reading = new ReadingTime(UserPreferences::fromStored(['reading_pace_pages' => 40, 'reading_pace_chapters' => 12]));

    expect($reading->of(ProgressUnit::Pages, 100))->toBe(150)
        ->and($reading->of(ProgressUnit::Chapters, 5))->toBe(25)
        ->and($reading->of(ProgressUnit::Issues, 3))->toBe((int) round(3 * 60 / jsonFixture('preferences/defaults')['reading_pace_issues']));
});

test('time already logged as time, or no unit at all, is not an estimate', function () {
    $reading = new ReadingTime(UserPreferences::fromStored([]));

    expect($reading->of(ProgressUnit::Minutes, 90))->toBeNull()
        ->and($reading->of(null, 90))->toBeNull();
});
