<?php

namespace App\Users\Statistics;

use App\Models\User;
use App\Support\Numbers;
use App\Support\Payload;
use Closure;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Cache;
use JsonSerializable;
use LogicException;

/**
 * The statistics page's sections as they were last worked out, so a second
 * look at the same view reads them instead of the whole history again.
 *
 * A section is kept under everything its answer depends on: the user's
 * library (a version that moves with every write to their history, ratings,
 * statuses or projection, and with every refresh of a title they have
 * history on: its genres, credits and everyone else's ratings), the release,
 * the user's settings and language, the view itself and the day it is for
 * the user (a period that runs to today grows with it). Change any of them
 * and the key changes; nothing is served past what it was worked out from. A
 * section is kept as the plain data the page receives, not as objects.
 */
final readonly class StatisticsCache
{
    /**
     * How long an answer is kept: the day it was worked out for, after which
     * its key can no longer be asked for. Not a guess at freshness, only a
     * bound on what lingers in the store.
     */
    private const KEEP_SECONDS = 86_400;

    /**
     * @param  Closure(): (JsonSerializable|object)  $build  works the section out when it is not kept
     * @return array<string, mixed> the section as the page receives it
     */
    public function remember(User $user, StatisticsPeriod $period, ?string $type, string $today, string $section, Closure $build): array
    {
        $key = implode(':', [
            'statistics',
            $user->id,
            $this->version(self::libraryVersion($user->id)),
            // The release (a new one may count or shape a section anew), the settings, the language and the view, in one fingerprint.
            hash('xxh128', json_encode([config()->string('tracker.version'), $user->preferences->resolved(), $user->preferences->effectiveTimezone(), App::getLocale(), $period, $type], JSON_THROW_ON_ERROR)),
            $today,
            $section,
        ]);

        $kept = Cache::get($key);

        if (is_string($kept)) {
            return self::decode($kept);
        }

        $encoded = json_encode($build(), JSON_THROW_ON_ERROR);
        Cache::put($key, $encoded, self::KEEP_SECONDS);

        return self::decode($encoded);
    }

    /** Something the user's statistics count changed: their kept sections no longer hold. */
    public function forgetLibrary(int $userId): void
    {
        self::bump(self::libraryVersion($userId));
    }

    /** A store that cannot count a key it has never held (the database one) starts it here. */
    private static function bump(string $key): void
    {
        if (Cache::increment($key) === false) {
            Cache::forever($key, 1);
        }
    }

    private static function libraryVersion(int $userId): string
    {
        return "statistics:library-version:{$userId}";
    }

    private function version(string $key): int
    {
        return Numbers::int(Cache::get($key)) ?? 0;
    }

    /**
     * @return array<string, mixed>
     */
    private static function decode(string $encoded): array
    {
        $decoded = json_decode($encoded, true, flags: JSON_THROW_ON_ERROR);

        // A section is an object with named fields; anything else was never written here.
        if (! is_array($decoded) || array_is_list($decoded)) {
            throw new LogicException('A kept statistics section is not an object.');
        }

        return Payload::named($decoded);
    }
}
