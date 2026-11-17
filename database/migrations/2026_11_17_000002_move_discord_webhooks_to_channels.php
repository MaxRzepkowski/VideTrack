<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;

/**
 * The one Discord webhook a user could save becomes their first notification
 * channel, keeping its delivery mode and the day its last digest claimed.
 * Encrypts the way the channel's `encrypted:array` cast does: the JSON of its
 * settings as one encrypted string.
 *
 * down() puts each user's first enabled Discord channel back into the user
 * columns; a second channel, a disabled one and every other kind of channel
 * are not restored.
 */
return new class extends Migration
{
    private const BATCH = 200;

    public function up(): void
    {
        DB::table('users')
            ->whereNotNull('discord_webhook_url')
            ->select(['id', 'discord_webhook_url', 'discord_digest_at', DB::raw("json_extract(preferences, '$.discord_mode') as discord_mode")])
            ->chunkById(self::BATCH, function (Collection $users): void {
                $now = now();
                $channels = [];

                foreach ($users as $user) {
                    $url = trim(Crypt::decryptString((string) $this->text($user, 'discord_webhook_url')));

                    if ($url === '') {
                        continue;
                    }

                    $channels[] = [
                        'user_id' => $this->id($user, 'id'),
                        'driver' => 'discord',
                        'kind' => 'discord',
                        'label' => 'Discord',
                        'config' => Crypt::encryptString(json_encode(['webhook_url' => $url], JSON_THROW_ON_ERROR)),
                        'mode' => $this->text($user, 'discord_mode') === 'instant' ? 'instant' : 'digest',
                        'enabled' => true,
                        'digest_sent_at' => $this->text($user, 'discord_digest_at'),
                        'created_at' => $now,
                        'updated_at' => $now,
                    ];
                }

                DB::table('notification_channels')->insert($channels);
            });

        DB::table('users')
            ->whereNotNull(DB::raw("json_extract(preferences, '$.discord_mode')"))
            ->update(['preferences' => DB::raw("json_remove(preferences, '$.discord_mode')")]);
    }

    public function down(): void
    {
        $first = DB::table('notification_channels')
            ->where('driver', 'discord')
            ->where('enabled', true)
            ->groupBy('user_id')
            ->select(DB::raw('min(id) as id'));

        foreach (DB::table('notification_channels')->whereIn('id', $first)->orderBy('id')->get() as $channel) {
            $config = json_decode(Crypt::decryptString((string) $this->text($channel, 'config')), true, flags: JSON_THROW_ON_ERROR);
            $url = is_array($config) && is_string($config['webhook_url'] ?? null) ? $config['webhook_url'] : null;

            if ($url === null) {
                continue;
            }

            $mode = $this->text($channel, 'mode') === 'instant' ? 'instant' : 'digest';

            DB::table('users')->where('id', $this->id($channel, 'user_id'))->update([
                'discord_webhook_url' => Crypt::encryptString($url),
                'discord_digest_at' => $this->text($channel, 'digest_sent_at'),
                'preferences' => DB::raw("json_set(coalesce(preferences, '{}'), '$.discord_mode', '{$mode}')"),
            ]);
        }

        DB::table('notification_channels')->where('driver', 'discord')->delete();
    }

    /** A text column of a raw row; null when it is empty or not text. */
    private function text(stdClass $row, string $column): ?string
    {
        $value = $row->{$column} ?? null;

        return is_string($value) ? $value : null;
    }

    /** An id column of a raw row, which SQLite may hand over as an integer or its digits. */
    private function id(stdClass $row, string $column): int
    {
        $value = $row->{$column} ?? null;

        return is_int($value) || (is_string($value) && ctype_digit($value)) ? (int) $value : throw new UnexpectedValueException("No id in [{$column}].");
    }
};
