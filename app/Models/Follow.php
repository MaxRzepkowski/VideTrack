<?php

namespace App\Models;

use App\Catalog\Creators\CreatorKind;
use App\Enums\FollowMode;
use App\Library\Sponsorships;
use Database\Factories\FollowFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * A per-item exception to the tracking matrix: the bell on a title, person,
 * company or collection page. Targets exactly one of `media_item_id`
 * (hierarchy roots only), `person_id`, `company_id` or `list_id` (a provider
 * collection). Absence of a row = the matrix alone decides.
 *
 * `viaPeople` / `viaCompanies` / `viaLists` are the provenance of an
 * auto-follow: every followed person, company or collection whose title
 * sponsors this row. The rules over them live in
 * {@see Sponsorships}.
 *
 * @property int $id
 * @property int $user_id
 * @property int|null $media_item_id
 * @property int|null $person_id
 * @property int|null $company_id
 * @property int|null $list_id
 * @property FollowMode $mode
 * @property-read Person|null $person
 * @property-read Company|null $company
 * @property-read MediaList|null $list
 * @property-read Collection<int, Person> $viaPeople
 * @property-read Collection<int, Company> $viaCompanies
 * @property-read Collection<int, MediaList> $viaLists
 * @property-read MediaItem|null $mediaItem
 * @property-read User $user
 */
class Follow extends Model
{
    /** @use HasFactory<FollowFactory> */
    use HasFactory;

    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'mode' => FollowMode::class,
        ];
    }

    /** The column a bell on a creator of this kind sits in. */
    public static function creatorColumn(CreatorKind $kind): string
    {
        return match ($kind) {
            CreatorKind::Person => 'person_id',
            CreatorKind::Company, CreatorKind::Network => 'company_id',
            CreatorKind::Collection => 'list_id',
        };
    }

    /**
     * The table recording that a creator of this kind backs a row, and its
     * creator column.
     *
     * @return array{0: string, 1: string}
     */
    public static function sponsorPivot(CreatorKind $kind): array
    {
        return match ($kind) {
            CreatorKind::Person => ['follow_person', 'person_id'],
            CreatorKind::Company, CreatorKind::Network => ['follow_company', 'company_id'],
            CreatorKind::Collection => ['follow_list', 'list_id'],
        };
    }

    /** The relation naming the creators of this kind behind a row. */
    public static function sponsorRelation(CreatorKind $kind): string
    {
        return match ($kind) {
            CreatorKind::Person => 'viaPeople',
            CreatorKind::Company, CreatorKind::Network => 'viaCompanies',
            CreatorKind::Collection => 'viaLists',
        };
    }

    /**
     * Rows some creator backs.
     *
     * @param  Builder<Follow>  $query
     */
    public function scopeSponsored(Builder $query): void
    {
        $query->where(fn (Builder $any) => $any
            ->whereHas('viaPeople')
            ->orWhereHas('viaCompanies')
            ->orWhereHas('viaLists'));
    }

    /**
     * Rows the user chose: a bell no creator rings, and every mute (a creator
     * may back a mute too, but muting is the user's act). An auto-follow is
     * the creator's, so it holds nothing in the user's library.
     *
     * @param  Builder<Follow>  $query
     */
    public function scopeChosenByUser(Builder $query): void
    {
        $query->where(fn (Builder $chosen) => $chosen
            ->where('mode', FollowMode::Mute->value)
            ->orWhere(fn (Builder $own) => $own->unsponsored()));
    }

    /**
     * Rows no creator backs: the user's own.
     *
     * @param  Builder<Follow>  $query
     */
    public function scopeUnsponsored(Builder $query): void
    {
        $query->whereDoesntHave('viaPeople')
            ->whereDoesntHave('viaCompanies')
            ->whereDoesntHave('viaLists');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return BelongsTo<MediaItem, $this>
     */
    public function mediaItem(): BelongsTo
    {
        return $this->belongsTo(MediaItem::class);
    }

    /**
     * @return BelongsTo<Person, $this>
     */
    public function person(): BelongsTo
    {
        return $this->belongsTo(Person::class);
    }

    /**
     * @return BelongsTo<Company, $this>
     */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    /**
     * The followed provider collection — always an ownerless system list.
     *
     * @return BelongsTo<MediaList, $this>
     */
    public function list(): BelongsTo
    {
        return $this->belongsTo(MediaList::class, 'list_id');
    }

    /**
     * @return BelongsToMany<Person, $this>
     */
    public function viaPeople(): BelongsToMany
    {
        return $this->belongsToMany(Person::class, 'follow_person');
    }

    /**
     * @return BelongsToMany<Company, $this>
     */
    public function viaCompanies(): BelongsToMany
    {
        return $this->belongsToMany(Company::class, 'follow_company');
    }

    /**
     * @return BelongsToMany<MediaList, $this>
     */
    public function viaLists(): BelongsToMany
    {
        return $this->belongsToMany(MediaList::class, 'follow_list', 'follow_id', 'list_id');
    }

    /**
     * Who rang this bell for the user — every followed person, company and
     * collection behind it, in one line; empty for the user's own bell. Reads
     * the three sponsor relations, so load them first.
     */
    public function sponsorNames(): string
    {
        return $this->viaPeople->pluck('name')
            ->concat($this->viaCompanies->pluck('name'))
            ->concat($this->viaLists->pluck('title'))
            ->implode(', ');
    }
}
