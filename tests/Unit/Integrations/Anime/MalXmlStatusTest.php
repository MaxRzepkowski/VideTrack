<?php

use App\Integrations\Anime\MalXmlStatus;
use App\Integrations\Export\ListStatus;

test('each word reads as the list status it names', function () {
    expect(MalXmlStatus::Watching->listStatus())->toBe(ListStatus::Current)
        ->and(MalXmlStatus::Reading->listStatus())->toBe(ListStatus::Current)
        ->and(MalXmlStatus::Completed->listStatus())->toBe(ListStatus::Completed)
        ->and(MalXmlStatus::OnHold->listStatus())->toBe(ListStatus::OnHold)
        ->and(MalXmlStatus::Dropped->listStatus())->toBe(ListStatus::Dropped)
        ->and(MalXmlStatus::PlanToWatch->listStatus())->toBe(ListStatus::Planned)
        ->and(MalXmlStatus::PlanToRead->listStatus())->toBe(ListStatus::Planned);
});

test('a status word is read in any case, and an unknown one is none', function () {
    expect(MalXmlStatus::read(' plan to watch '))->toBe(MalXmlStatus::PlanToWatch)
        ->and(MalXmlStatus::read('ON-HOLD'))->toBe(MalXmlStatus::OnHold)
        ->and(MalXmlStatus::read('Rewatching'))->toBeNull();
});
