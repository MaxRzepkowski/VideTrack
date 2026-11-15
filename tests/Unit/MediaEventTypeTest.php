<?php

use App\Accounts\Preferences\NotificationEvent;
use App\Notifications\MediaEventType;

test('every event answers to the switch that silences it, and only these to none', function () {
    $silent = array_filter(MediaEventType::cases(), fn (MediaEventType $type): bool => $type->preference() === null);

    expect(array_values($silent))->toBe([MediaEventType::HomeRelease, MediaEventType::TitlesRemoved])
        ->and(MediaEventType::SeasonAnnounced->preference())->toBe(NotificationEvent::ShowStatus)
        ->and(MediaEventType::DateChanged->preference())->toBe(NotificationEvent::DateChanges);
});
