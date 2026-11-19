<?php

namespace App\Models;

use Database\Factories\PushSubscriptionFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Minishlink\WebPush\ContentEncoding;

/**
 * One browser a web push channel delivers to. Its address and keys are
 * credentials, encrypted at rest and hidden from serialization; the address
 * is looked up by its hash.
 *
 * @property int $id
 * @property int $notification_channel_id
 * @property string $endpoint_hash
 * @property string $endpoint
 * @property string $public_key the browser's key a message is encrypted to
 * @property string $auth_token
 * @property ContentEncoding $content_encoding
 * @property string $vapid_key the instance's public key it subscribed with
 * @property string|null $user_agent
 * @property Carbon $created_at
 * @property-read NotificationChannel $channel
 */
class PushSubscription extends Model
{
    /** @use HasFactory<PushSubscriptionFactory> */
    use HasFactory;

    protected $guarded = [];

    /** @var list<string> */
    protected $hidden = ['endpoint', 'public_key', 'auth_token'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'endpoint' => 'encrypted',
            'public_key' => 'encrypted',
            'auth_token' => 'encrypted',
            'content_encoding' => ContentEncoding::class,
        ];
    }

    /** The hash a browser's address is kept and found under. */
    public static function hashOf(string $endpoint): string
    {
        return hash('sha256', $endpoint);
    }

    /**
     * The subscription of one browser.
     *
     * @param  Builder<self>  $query
     */
    public function scopeOfEndpoint(Builder $query, string $endpoint): void
    {
        $query->where('endpoint_hash', self::hashOf($endpoint));
    }

    /**
     * The browsers one channel delivers to.
     *
     * @param  Builder<self>  $query
     */
    public function scopeOfChannel(Builder $query, int $channelId): void
    {
        $query->where('notification_channel_id', $channelId);
    }

    /** Whether it was made with another key pair than the instance's: no message reaches it any more. */
    public function isStale(string $vapidKey): bool
    {
        return $this->vapid_key !== $vapidKey;
    }

    /**
     * @return BelongsTo<NotificationChannel, $this>
     */
    public function channel(): BelongsTo
    {
        return $this->belongsTo(NotificationChannel::class, 'notification_channel_id');
    }
}
