<?php

use App\Integrations\Discord\DiscordAnswer;

test('Discord answers read as delivered, gone, throttled, refused or unavailable', function () {
    expect(DiscordAnswer::ofStatus(204))->toBe(DiscordAnswer::Delivered)
        ->and(DiscordAnswer::ofStatus(404))->toBe(DiscordAnswer::Gone)
        ->and(DiscordAnswer::ofStatus(401))->toBe(DiscordAnswer::Gone)
        ->and(DiscordAnswer::ofStatus(429))->toBe(DiscordAnswer::Throttled)
        ->and(DiscordAnswer::ofStatus(400))->toBe(DiscordAnswer::Refused)
        ->and(DiscordAnswer::ofStatus(502))->toBe(DiscordAnswer::Unavailable);
});
