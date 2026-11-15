<?php

namespace App\Models;

use App\Enums\WebhookDeliveryStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One thing a source sent us, and what became of it. Kept even when nothing
 * came of it: "your media server posted, we could not place the title" is the
 * answer to almost every support question about scrobbling.
 *
 * @property int $id
 * @property int $webhook_endpoint_id
 * @property WebhookDeliveryStatus $status
 * @property string|null $kind
 * @property string|null $media_label
 * @property string|null $message i18n key naming the reason
 * @property array<string, mixed>|null $payload
 * @property Carbon|null $created_at
 * @property-read WebhookEndpoint $webhookEndpoint
 */
class WebhookDelivery extends Model
{
    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => WebhookDeliveryStatus::class,
            'payload' => 'array',
        ];
    }

    /**
     * @return BelongsTo<WebhookEndpoint, $this>
     */
    public function webhookEndpoint(): BelongsTo
    {
        return $this->belongsTo(WebhookEndpoint::class);
    }
}
