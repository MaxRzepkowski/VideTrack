<?php

namespace App\Models;

use App\Notifications\Channels\ChannelMode;
use App\Support\Delivery\DeliveryAnswer;
use Database\Factories\NotificationChannelFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One place a user's notifications go beyond the bell: a webhook, a set of
 * addresses of a delivery service… Its settings are credentials, encrypted at rest
 * and hidden from serialization — the settings page learns only that they
 * are saved.
 *
 * @property int $id
 * @property int $user_id
 * @property string $driver the code its driver is registered under
 * @property string $kind which of the driver's services
 * @property string $label
 * @property array<string, mixed> $config
 * @property ChannelMode $mode
 * @property bool $enabled
 * @property DeliveryAnswer|null $last_failure
 * @property Carbon|null $digest_sent_at
 * @property Carbon $created_at
 * @property-read User $user
 */
class NotificationChannel extends Model
{
    /** @use HasFactory<NotificationChannelFactory> */
    use HasFactory;

    /** How long a channel's name may be. */
    public const LABEL_MAX = 60;

    protected $guarded = [];

    /** @var list<string> */
    protected $hidden = ['config'];

    /** @var array<string, mixed> */
    protected $attributes = ['enabled' => true];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'config' => 'encrypted:array',
            'mode' => ChannelMode::class,
            'enabled' => 'boolean',
            'last_failure' => DeliveryAnswer::class,
            'digest_sent_at' => 'datetime',
        ];
    }

    /**
     * Enabled channels of one mode.
     *
     * @param  Builder<self>  $query
     */
    public function scopeDelivering(Builder $query, ChannelMode $mode): void
    {
        $query->where('enabled', true)->where('mode', $mode);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
