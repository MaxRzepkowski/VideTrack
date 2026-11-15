<?php

namespace App\Models;

use Database\Factories\WebhookEndpointFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * One address a scrobble source posts to. The token in the URL is the whole
 * credential — a capability, like the calendar feed token — so it is hidden
 * from serialization and never travels anywhere but the address itself.
 *
 * @property int $id
 * @property int $user_id
 * @property string $source scrobble source code ("jellyfin", …)
 * @property string|null $label the user's own name for this endpoint
 * @property array<string, mixed>|null $settings per-source options
 * @property string $token
 * @property bool $enabled
 * @property Carbon|null $last_received_at
 * @property string|null $last_error
 * @property-read User $user
 */
class WebhookEndpoint extends Model
{
    /** @use HasFactory<WebhookEndpointFactory> */
    use HasFactory;

    protected $guarded = [];

    /** @var list<string> */
    protected $hidden = ['token', 'token_hash'];

    /**
     * The endpoint a request's token names.
     *
     * @param  Builder<WebhookEndpoint>  $query
     */
    public function scopeWithToken(Builder $query, string $token): void
    {
        $query->where('token_hash', self::tokenHash($token));
    }

    /** How a token is found: stored encrypted, looked up by this hash. */
    public static function tokenHash(string $token): string
    {
        return hash('sha256', $token);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'token' => 'encrypted',
            'enabled' => 'boolean',
            'settings' => 'array',
            'last_received_at' => 'datetime',
        ];
    }

    /**
     * The media server account whose playback this endpoint accepts — null
     * when it accepts everybody's, which is what a one-person server wants.
     */
    public function serverUsername(): ?string
    {
        $name = $this->settings['server_username'] ?? null;

        return is_string($name) && trim($name) !== '' ? trim($name) : null;
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return HasMany<WebhookDelivery, $this>
     */
    public function deliveries(): HasMany
    {
        return $this->hasMany(WebhookDelivery::class);
    }
}
