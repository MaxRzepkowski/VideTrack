<?php

use App\Integrations\Discord\DiscordChannel;
use App\Jobs\SendChannelDigests;
use App\Models\NotificationChannel;
use App\Models\TaskRun;
use App\Models\User;
use App\Notifications\Channels\ChannelMode;
use App\Notifications\Channels\DailyDigests;
use App\Tasks\TaskName;

// Notification channels as the settings page saves them, and the hourly digest run.

const DISCORD_WEBHOOK = 'https://discord.com/api/webhooks/123/abc';

function discordChannel(User $user, ChannelMode $mode = ChannelMode::Digest, string $webhook = DISCORD_WEBHOOK): NotificationChannel
{
    return NotificationChannel::factory()->for($user)->create(['mode' => $mode, 'config' => [DiscordChannel::WEBHOOK => $webhook]]);
}

function runChannelDigests(): void
{
    (new SendChannelDigests(TaskRun::queueTask(TaskName::ChannelDigest->value)))
        ->handle(app(DailyDigests::class));
}
