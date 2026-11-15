<?php

namespace App\Models;

use App\Casts\AsLocalizedTitles;
use App\Catalog\Data\LocalizedTitle;
use App\Catalog\DatePrecision;
use App\Catalog\TitleStatus;
use App\Support\Locales;
use Carbon\CarbonInterface;
use Database\Factories\MediaItemFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * A locally persisted media position (movie / show / season / episode / …).
 *
 * Rows exist only for items the user has interacted with; browsing runs on
 * the metadata provider cache. Type-specific fields live in `metadata`.
 *
 * @property int $id
 * @property string $type
 * @property string $provider
 * @property string $provider_ref
 * @property int|null $parent_id
 * @property int|null $number
 * @property string $title
 * @property int|null $year
 * @property Carbon|null $release_date
 * @property DatePrecision $release_date_precision how much of `release_date` the provider knows (a stand-in day for "2028")
 * @property Carbon|null $release_time
 * @property Carbon|null $home_release_date the earlier of a film's digital / disc release, any region
 * @property int|null $runtime
 * @property int|null $total_runtime minutes for the whole item (a show: every episode)
 * @property string|null $overview
 * @property string|null $poster_path
 * @property array<string, LocalizedTitle> $localized_titles locale => the title's name and poster there
 * @property string|null $backdrop_path
 * @property float|null $community_rating
 * @property TitleStatus|null $status
 * @property array<string, mixed>|null $metadata
 * @property Carbon|null $refreshed_at
 * @property Carbon|null $gone_at when the data source stopped having this root, which stays as an archive entry
 */
class MediaItem extends Model
{
    /** @use HasFactory<MediaItemFactory> */
    use HasFactory;

    protected $guarded = [];

    /** The column's default, so a row built in memory reads the same before it is saved. */
    protected $attributes = [
        'release_date_precision' => 'day',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'release_date' => 'date',
            'release_date_precision' => DatePrecision::class,
            'release_time' => 'datetime',
            'home_release_date' => 'date',
            'community_rating' => 'float',
            'status' => TitleStatus::class,
            'metadata' => 'array',
            'localized_titles' => AsLocalizedTitles::class,
            'refreshed_at' => 'datetime',
            'gone_at' => 'datetime',
        ];
    }

    /**
     * Out by the end of `$today`. The one rule for "aired"; the caller says
     * whose today it is.
     */
    public function hasAired(CarbonInterface $today): bool
    {
        return self::airedBy($this->release_date?->toDateString(), $today);
    }

    /** The same rule for a release date read off a card (`Y-m-d`): out by the end of `$today`; no date is not out. */
    public static function airedBy(?string $releaseDate, CarbonInterface $today): bool
    {
        return $releaseDate !== null && substr($releaseDate, 0, 10) < substr(self::airedCutoff($today), 0, 10);
    }

    /**
     * The same rule as an `aired` column, for rows read without models.
     *
     * @param  Builder<MediaItem>  $query
     */
    public function scopeWithAiredFlag(Builder $query, CarbonInterface $today): void
    {
        $query->selectRaw('release_date is not null and release_date < ? as aired', [self::airedCutoff($today)]);
    }

    /**
     * Roots that can still change in a way a user is told about: a container
     * (a finished show can still gain a season), a status with more to come,
     * or a release date not yet known or not yet past. The one rule for
     * "open"; the caller names the container types.
     *
     * @param  Builder<MediaItem>  $query
     * @param  list<string>  $containerTypes
     */
    public function scopeOpen(Builder $query, array $containerTypes, CarbonInterface $today): void
    {
        $query->where(fn (Builder $open) => $open
            ->whereIn('type', $containerTypes)
            ->orWhere(fn (Builder $status) => $status
                ->whereNotNull('status')
                ->whereNotIn('status', array_map(fn (TitleStatus $final): string => $final->value, TitleStatus::finals())))
            ->orWhereNull('release_date')
            // Plain comparisons: the columns are stored midnight-stamped.
            ->orWhere('release_date', '>=', $today->toDateString())
            ->orWhere('home_release_date', '>=', $today->toDateString()));
    }

    /** Release dates are stored midnight-stamped; anything before the next day's midnight is out. */
    public static function airedCutoff(CarbonInterface $today): string
    {
        return $today->copy()->addDay()->startOfDay()->toDateTimeString();
    }

    /**
     * The name a reader of `$locale` knows the title by: their language's
     * version, else the fallback language's, else the row's own — the one
     * version of a provider without languages, or of a row that holds no
     * language versions.
     */
    public function titleIn(string $locale): string
    {
        return $this->localizedTitle($locale)->title ?? $this->title;
    }

    /** The poster a reader of `$locale` sees, by the same walk as titleIn(). */
    public function posterIn(string $locale): ?string
    {
        return $this->localizedTitle($locale)->posterPath ?? $this->poster_path;
    }

    private function localizedTitle(string $locale): ?LocalizedTitle
    {
        $titles = $this->localized_titles;

        return $titles[$locale] ?? $titles[Locales::fallback()] ?? null;
    }

    /**
     * @return BelongsTo<MediaItem, $this>
     */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    /**
     * @return HasMany<MediaItem, $this>
     */
    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id')->orderBy('number');
    }

    /**
     * @return HasMany<Credit, $this>
     */
    public function credits(): HasMany
    {
        return $this->hasMany(Credit::class);
    }

    /**
     * @return HasMany<Play, $this>
     */
    public function plays(): HasMany
    {
        return $this->hasMany(Play::class);
    }

    /**
     * @return HasMany<Rating, $this>
     */
    public function ratings(): HasMany
    {
        return $this->hasMany(Rating::class);
    }

    /**
     * @return HasMany<ListItem, $this>
     */
    public function listItems(): HasMany
    {
        return $this->hasMany(ListItem::class);
    }
}
