<?php

use App\Integrations\Anime\ListMedium;
use App\Integrations\Anime\MalXmlStatus;
use App\Integrations\Export\ListStatus;

test('every list status is written in the words of its list and read back the same', function (ListMedium $medium) {
    foreach (ListStatus::cases() as $status) {
        $word = MalXmlStatus::of($status, $medium);

        expect(MalXmlStatus::read($word->value)?->listStatus())->toBe($status);
    }
})->with([ListMedium::Anime, ListMedium::Manga]);

test('a status word is read in any case, and an unknown one is none', function () {
    expect(MalXmlStatus::read(' plan to watch '))->toBe(MalXmlStatus::PlanToWatch)
        ->and(MalXmlStatus::read('ON-HOLD'))->toBe(MalXmlStatus::OnHold)
        ->and(MalXmlStatus::read('Rewatching'))->toBeNull();
});

test('each list watches or reads in its own words', function () {
    expect(MalXmlStatus::of(ListStatus::Current, ListMedium::Anime))->toBe(MalXmlStatus::Watching)
        ->and(MalXmlStatus::of(ListStatus::Current, ListMedium::Manga))->toBe(MalXmlStatus::Reading)
        ->and(MalXmlStatus::of(ListStatus::Planned, ListMedium::Manga))->toBe(MalXmlStatus::PlanToRead);
});
