<?php

use App\Models\NotificationChannel;
use App\Notifications\Channels\ChannelMode;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;

/*
 * The Discord webhook an instance saved on the user becomes their first
 * notification channel, mode and digest slot kept, and the digest task's past
 * runs follow it to its new name. Run on a scratch database rolled back to
 * the schema before the move.
 */

const CHANNEL_MIGRATIONS = 4;

function scratchBeforeChannels(): ConnectionInterface
{
    $file = scratchPath('channels', '.sqlite');
    config(['database.connections.scratch' => [...config()->array('database.connections.sqlite'), 'database' => $file]]);

    Artisan::call('migrate', ['--database' => 'scratch', '--force' => true]);
    Artisan::call('migrate:rollback', ['--database' => 'scratch', '--force' => true, '--step' => CHANNEL_MIGRATIONS]);

    return DB::connection('scratch');
}

/**
 * @param  array<string, mixed>  $preferences
 */
function scratchUser(ConnectionInterface $db, string $username, ?string $webhook, array $preferences = [], ?string $digestAt = null): int
{
    return $db->table('users')->insertGetId([
        'name' => $username,
        'username' => $username,
        'email' => "{$username}@example.com",
        'password' => 'x',
        'preferences' => json_encode(['timezone' => 'UTC', ...$preferences]),
        'discord_webhook_url' => $webhook === null ? null : Crypt::encryptString($webhook),
        'discord_digest_at' => $digestAt,
    ]);
}

test('a saved webhook becomes a Discord channel with its mode and digest slot', function () {
    $db = scratchBeforeChannels();
    $alex = scratchUser($db, 'alex', 'https://discord.com/api/webhooks/1/a', ['discord_mode' => 'instant']);
    $sam = scratchUser($db, 'sam', 'https://discord.com/api/webhooks/2/b', digestAt: '2026-10-10 06:00:00');
    $kim = scratchUser($db, 'kim', null, ['discord_mode' => 'instant']);
    $db->table('task_runs')->insert(['name' => 'discord-digest', 'status' => 'success', 'queued_at' => '2026-10-10 06:00:00']);

    Artisan::call('migrate', ['--database' => 'scratch', '--force' => true]);

    $channels = (new NotificationChannel)->setConnection('scratch')->newQuery()->orderBy('user_id')->get();

    expect($channels)->toHaveCount(2)
        ->and($channels[0]->user_id)->toBe($alex)
        ->and($channels[0]->driver)->toBe('discord')
        ->and($channels[0]->config)->toBe(['webhook_url' => 'https://discord.com/api/webhooks/1/a'])
        ->and($channels[0]->mode)->toBe(ChannelMode::Instant)
        ->and($channels[0]->enabled)->toBeTrue()
        ->and($channels[0]->digest_sent_at)->toBeNull()
        ->and($channels[1]->user_id)->toBe($sam)
        ->and($channels[1]->mode)->toBe(ChannelMode::Digest)
        ->and($channels[1]->digest_sent_at?->toDateTimeString())->toBe('2026-10-10 06:00:00')
        // The mode is the channel's now; the preference is gone, also where no webhook was saved.
        ->and($db->table('users')->where('id', $kim)->value('preferences'))->toBe('{"timezone":"UTC"}')
        ->and($db->table('task_runs')->pluck('name')->all())->toBe(['channel-digest']);
});

test('rolling back puts the first Discord channel back on the user', function () {
    $db = scratchBeforeChannels();
    $alex = scratchUser($db, 'alex', 'https://discord.com/api/webhooks/1/a', ['discord_mode' => 'instant'], '2026-10-10 06:00:00');
    Artisan::call('migrate', ['--database' => 'scratch', '--force' => true]);

    Artisan::call('migrate:rollback', ['--database' => 'scratch', '--force' => true, '--step' => CHANNEL_MIGRATIONS - 1]);

    $user = $db->table('users')->where('id', $alex)->first();

    expect(Crypt::decryptString((string) $user?->discord_webhook_url))->toBe('https://discord.com/api/webhooks/1/a')
        ->and($user?->discord_digest_at)->toBe('2026-10-10 06:00:00')
        ->and(json_decode((string) $user?->preferences, true))->toBe(['timezone' => 'UTC', 'discord_mode' => 'instant'])
        ->and($db->table('notification_channels')->count())->toBe(0);
});
