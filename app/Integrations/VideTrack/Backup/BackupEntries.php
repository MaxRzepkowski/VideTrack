<?php

namespace App\Integrations\VideTrack\Backup;

use App\Enums\FollowMode;
use App\Library\MutationSource;
use App\Models\Favorite;
use App\Models\Follow;
use App\Models\MediaList;
use App\Models\MediaUserState;
use App\Models\Play;
use App\Models\Rating;
use App\Models\User;
use App\Models\WatchCycle;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Generator;
use Illuminate\Database\Eloquent\Builder;

/**
 * A user's library read as backup entries, one section at a time and in
 * chunks, so a long history never sits in memory whole. The backup writes
 * every section; the spreadsheet downloads read the one they show.
 */
final readonly class BackupEntries
{
    private const CHUNK = 500;

    /**
     * @return Generator<int, BackupEntry>
     */
    public function section(User $user, BackupSection $section): Generator
    {
        return match ($section) {
            BackupSection::Cycles => $this->cycles($user),
            BackupSection::Follows => $this->follows($user),
            BackupSection::Plays => $this->plays($user),
            BackupSection::Ratings => $this->ratings($user),
            BackupSection::Favorites => $this->favorites($user),
            BackupSection::Statuses => $this->statuses($user),
            BackupSection::Lists => $this->lists($user),
        };
    }

    /**
     * In the order they happened.
     *
     * @return Generator<int, BackupPlay>
     */
    public function plays(User $user): Generator
    {
        $plays = $user->plays()
            ->with(['mediaItem', 'watchCycle:id,number'])
            ->orderBy('watched_at')
            ->orderBy('id')
            ->lazy(self::CHUNK);

        foreach ($plays as $play) {
            /** @var Play $play */
            yield new BackupPlay(
                BackupPosition::of($play->mediaItem),
                CarbonImmutable::instance($play->watched_at),
                $play->outcome,
                $play->amount === null ? null : (int) $play->amount,
                $play->cycleNumber(),
                MutationSource::tryFromValue($play->source),
            );
        }
    }

    /**
     * @return Generator<int, BackupRating>
     */
    public function ratings(User $user): Generator
    {
        foreach ($user->ratings()->with('mediaItem')->lazyById(self::CHUNK) as $rating) {
            /** @var Rating $rating */
            yield new BackupRating(BackupPosition::of($rating->mediaItem), $rating->rating, $this->moment($rating->updated_at));
        }
    }

    /**
     * @return Generator<int, BackupFavorite>
     */
    public function favorites(User $user): Generator
    {
        foreach ($user->favorites()->with('mediaItem')->lazyById(self::CHUNK) as $favorite) {
            /** @var Favorite $favorite */
            yield new BackupFavorite(BackupPosition::of($favorite->mediaItem), $this->moment($favorite->created_at));
        }
    }

    /**
     * @return Generator<int, BackupStatus>
     */
    public function statuses(User $user): Generator
    {
        foreach ($user->mediaStates()->with('mediaItem')->lazyById(self::CHUNK) as $state) {
            /** @var MediaUserState $state */
            yield new BackupStatus(
                BackupPosition::of($state->mediaItem),
                $state->status,
                $this->moment($state->created_at),
                $state->progress === null ? null : (int) $state->progress,
            );
        }
    }

    /**
     * In the order the user arranged them: a restore makes them one after
     * another, so the order travels as the order of the entries.
     *
     * @return Generator<int, BackupList>
     */
    public function lists(User $user): Generator
    {
        $lists = $user->lists()
            ->with(['items.mediaItem', 'items.person', 'items.company'])
            ->orderBy('position')
            ->orderBy('id')
            ->lazy(self::CHUNK);

        foreach ($lists as $list) {
            /** @var MediaList $list */
            yield BackupList::of($list);
        }
    }

    /**
     * @return Generator<int, BackupCycle>
     */
    public function cycles(User $user): Generator
    {
        $cycles = WatchCycle::query()
            ->where('user_id', $user->id)
            ->with('mediaItem')
            ->lazyById(self::CHUNK);

        foreach ($cycles as $cycle) {
            /** @var WatchCycle $cycle */
            yield new BackupCycle(BackupPosition::of($cycle->mediaItem), $cycle->number, CarbonImmutable::instance($cycle->started_at));
        }
    }

    /**
     * The bells the user rang or silenced; an auto-follow a creator rang is
     * left to come back with the creator's bell.
     *
     * @return Generator<int, BackupFollow>
     */
    public function follows(User $user): Generator
    {
        $follows = $user->follows()
            ->where(fn (Builder $own) => $own
                ->where('mode', FollowMode::Mute->value)
                ->orWhere(fn (Builder $unsponsored) => $unsponsored->unsponsored()))
            ->with(['mediaItem', 'person', 'company', 'list'])
            ->lazyById(self::CHUNK);

        foreach ($follows as $follow) {
            /** @var Follow $follow */
            yield BackupFollow::of($follow);
        }
    }

    private function moment(?DateTimeInterface $at): ?CarbonImmutable
    {
        return $at === null ? null : CarbonImmutable::instance($at);
    }
}
