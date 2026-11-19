<?php

namespace App\Users;

use App\Enums\ActivityGrouping;
use App\Enums\ActivitySort;
use App\Models\Favorite;
use App\Models\Play;
use App\Models\Rating;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * What an activity panel lists: the table its entries live in and the column
 * that dates an entry there.
 */
enum ActivitySource: string
{
    case Plays = 'plays';
    case Ratings = 'ratings';
    case Favorites = 'favorites';

    /** How the panel lays its entries out until the viewer picks otherwise. */
    public function defaultGrouping(): ActivityGrouping
    {
        return $this === self::Plays ? ActivityGrouping::Day : ActivityGrouping::Grid;
    }

    /** The axis the panel opens on: favorites carry only the one date, ratings the owner's score. */
    public function defaultSort(PanelReader $reader): ActivitySort
    {
        return match ($this) {
            self::Plays => ActivitySort::Activity,
            self::Ratings => $reader === PanelReader::Owner ? ActivitySort::MyRating : ActivitySort::OwnerRating,
            self::Favorites => ActivitySort::Added,
        };
    }

    /**
     * Times finished counts plays: a rating or a favorite is never finished.
     * A favorite carries no date beyond when it was added, so its activity
     * would repeat that axis. The owner's rating is an axis apart only for a
     * visitor — for the owner it is their own.
     */
    public function offers(ActivitySort $sort, PanelReader $reader): bool
    {
        return match ($sort) {
            ActivitySort::Activity => $this !== self::Favorites,
            ActivitySort::Completions => $this === self::Plays,
            ActivitySort::OwnerRating => $reader === PanelReader::Visitor,
            default => true,
        };
    }

    /** Only a play has an outcome (completed, dropped…) to filter by. */
    public function hasOutcome(): bool
    {
        return $this === self::Plays;
    }

    /**
     * The column an entry is dated by: a play's watch, a rating's last edit, a favorite's creation.
     *
     * @return literal-string
     */
    public function dateColumn(): string
    {
        return match ($this) {
            self::Plays => 'watched_at',
            self::Ratings => 'updated_at',
            self::Favorites => 'created_at',
        };
    }

    /**
     * The user's entries, joined to their media items.
     *
     * @return Builder<Model>
     */
    public function entriesOf(User $user): Builder
    {
        /** @var class-string<Model> $model */
        $model = match ($this) {
            self::Plays => Play::class,
            self::Ratings => Rating::class,
            self::Favorites => Favorite::class,
        };

        return $model::query()
            ->where("{$this->value}.user_id", $user->id)
            ->join('media_items', 'media_items.id', '=', "{$this->value}.media_item_id")
            ->select("{$this->value}.*");
    }
}
