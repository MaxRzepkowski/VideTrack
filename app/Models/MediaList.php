<?php

namespace App\Models;

use Database\Factories\MediaListFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use LogicException;

/**
 * A user list of mixed-type media positions. The watchlist is a system list
 * (system_key = 'watchlist'): made with the account, not deletable, with no
 * title of its own — the UI names it in the reader's language. Custom lists
 * (system_key = null) get full CRUD. Provider collections (system_key = "collection:{provider}:{ref}")
 * are ownerless system lists (user_id = null) authored by "VideTrack":
 * materialized on first visit, synced by a scheduled task, read-only for
 * everyone.
 *
 * @property int $id
 * @property int|null $user_id
 * @property string|null $title null for the watchlist, which the UI names
 * @property string|null $description
 * @property bool $is_public
 * @property string|null $system_key
 * @property string|null $backdrop_path
 * @property array{backdrops: list<string>, posters: list<string>}|null $images
 * @property array<array-key, mixed>|null $collection_relations what a provider collection is tied to beside its parts, as `CollectionRelations` stores it
 * @property int $position
 * @property-read int|null $items_count
 */
class MediaList extends Model
{
    /** @use HasFactory<MediaListFactory> */
    use HasFactory;

    public const SYSTEM_WATCHLIST = 'watchlist';

    private const COLLECTION_PREFIX = 'collection:';

    public static function collectionKey(string $provider, string $ref): string
    {
        return self::COLLECTION_PREFIX."{$provider}:{$ref}";
    }

    public function isCollection(): bool
    {
        return str_starts_with((string) $this->system_key, self::COLLECTION_PREFIX);
    }

    /**
     * The order the user arranged their lists in. A list never moved sorts by
     * creation, the watchlist first among equals.
     *
     * @param  Builder<MediaList>  $query
     * @return Builder<MediaList>
     */
    public function scopeInManualOrder(Builder $query): Builder
    {
        return $query->orderBy('position')->orderByRaw('system_key is null')->orderBy('id');
    }

    /**
     * @param  Builder<MediaList>  $query
     * @return Builder<MediaList>
     */
    public function scopeCollections(Builder $query): Builder
    {
        return $query->whereNull('user_id')->where('system_key', 'like', self::COLLECTION_PREFIX.'%');
    }

    /**
     * @return array{provider: string, ref: string}|null
     */
    public function collectionSource(): ?array
    {
        if (! $this->isCollection()) {
            return null;
        }

        [$provider, $ref] = explode(':', substr((string) $this->system_key, strlen(self::COLLECTION_PREFIX)), 2);

        return ['provider' => $provider, 'ref' => $ref];
    }

    protected $table = 'lists';

    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_public' => 'boolean',
            'images' => 'array',
            'collection_relations' => 'array',
        ];
    }

    public function isSystem(): bool
    {
        return $this->system_key !== null;
    }

    /**
     * System list titles render translated; custom titles are user content.
     */
    public function displayTitle(): string
    {
        if ($this->system_key === self::SYSTEM_WATCHLIST) {
            return __('common.backlog');
        }

        return $this->title ?? throw new LogicException('Only the watchlist goes without a title.');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return HasMany<ListItem, $this>
     */
    public function items(): HasMany
    {
        return $this->hasMany(ListItem::class, 'list_id')->orderBy('position');
    }
}
