<?php

namespace App\Models;

use App\Accounts\Preferences\UserPreferences;
use App\Casts\AsUserPreferences;
use App\Enums\UserRole;
use Database\Factories\UserFactory;
use Illuminate\Contracts\Translation\HasLocalePreference;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\HasApiTokens;
use LogicException;

/**
 * @property UserRole $role
 * @property string|null $password null for an account made through an identity provider
 * @property UserPreferences $preferences
 * @property Carbon|null $blocked_at
 * @property string|null $locale
 */
class User extends Authenticatable implements HasLocalePreference
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, Notifiable;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'username',
        'email',
        'password',
        'locale',
        'bio',
        'is_private',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
        'calendar_token',
        'calendar_token_hash',
    ];

    /**
     * The user a calendar feed token belongs to.
     *
     * @param  Builder<User>  $query
     */
    public function scopeWithCalendarToken(Builder $query, string $token): void
    {
        $query->where('calendar_token_hash', self::calendarTokenHash($token));
    }

    /** How a feed token is found: stored encrypted, looked up by this hash. */
    public static function calendarTokenHash(string $token): string
    {
        return hash('sha256', $token);
    }

    /**
     * The recipient's UI language, so Laravel localizes queued notifications
     * (e.g. the password-reset email) to them — the SetUserLocale middleware
     * only covers authenticated web requests, not the unauthenticated reset
     * flow. Falls back to the instance default.
     */
    public function preferredLocale(): string
    {
        return $this->locale ?? config()->string('tracker.default_locale');
    }

    /**
     * The token of this user's public iCal feed, issued with the account
     * (IssueCalendarToken).
     */
    public function calendarToken(): string
    {
        return $this->calendar_token ?? throw new LogicException('Every account is issued a calendar token.');
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'role' => UserRole::class,
            'preferences' => AsUserPreferences::class,
            'calendar_token' => 'encrypted',
            'is_private' => 'boolean',
            'blocked_at' => 'datetime',
        ];
    }

    /**
     * The avatar's public URL — asked for where it is shown, never appended
     * to every serialization.
     *
     * @return Attribute<string|null, never>
     */
    protected function avatar(): Attribute
    {
        return Attribute::get(fn (): ?string => $this->avatar_path === null ? null : Storage::disk('public')->url($this->avatar_path));
    }

    public function isAdmin(): bool
    {
        return $this->role === UserRole::Admin;
    }

    public function isBlocked(): bool
    {
        return $this->blocked_at !== null;
    }

    public function hasPassword(): bool
    {
        return $this->password !== null;
    }

    /**
     * Newest first. The daily run writes many in the same second; their ids
     * are time-ordered, so the id settles which came last — otherwise it is
     * whatever the index answering the query happens to hold.
     *
     * @return MorphMany<DatabaseNotification, $this>
     */
    public function notifications(): MorphMany
    {
        return $this->morphMany(DatabaseNotification::class, 'notifiable')->latest()->orderByDesc('id');
    }

    /**
     * @return HasMany<NotificationChannel, $this>
     */
    public function notificationChannels(): HasMany
    {
        return $this->hasMany(NotificationChannel::class);
    }

    /**
     * @return HasMany<OutgoingWebhook, $this>
     */
    public function outgoingWebhooks(): HasMany
    {
        return $this->hasMany(OutgoingWebhook::class);
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
     * @return HasMany<Favorite, $this>
     */
    public function favorites(): HasMany
    {
        return $this->hasMany(Favorite::class);
    }

    /**
     * @return HasMany<MediaList, $this>
     */
    public function lists(): HasMany
    {
        return $this->hasMany(MediaList::class);
    }

    /**
     * @return HasMany<ServiceConnection, $this>
     */
    public function serviceConnections(): HasMany
    {
        return $this->hasMany(ServiceConnection::class);
    }

    /**
     * @return HasMany<Identity, $this>
     */
    public function identities(): HasMany
    {
        return $this->hasMany(Identity::class);
    }

    /**
     * @return HasMany<WebhookEndpoint, $this>
     */
    public function webhookEndpoints(): HasMany
    {
        return $this->hasMany(WebhookEndpoint::class);
    }

    /**
     * @return HasMany<MediaUserState, $this>
     */
    public function mediaStates(): HasMany
    {
        return $this->hasMany(MediaUserState::class);
    }

    /**
     * @return HasMany<Follow, $this>
     */
    public function follows(): HasMany
    {
        return $this->hasMany(Follow::class);
    }

    /** Memoized per instance — watchlist() is resolved several times per request. */
    private ?MediaList $memoizedWatchlist = null;

    /**
     * The system watchlist; every account has one from the moment it is made
     * (CreateAccount).
     */
    public function watchlist(): MediaList
    {
        return $this->memoizedWatchlist ??= $this->lists()->where('system_key', MediaList::SYSTEM_WATCHLIST)->firstOrFail();
    }
}
