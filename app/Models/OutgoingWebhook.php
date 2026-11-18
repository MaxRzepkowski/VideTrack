<?php

namespace App\Models;

use App\Integrations\OutgoingWebhooks\WebhookEventGroup;
use App\Support\Delivery\DeliveryAnswer;
use Database\Factories\OutgoingWebhookFactory;
use Illuminate\Database\Eloquent\Casts\AsEnumCollection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use LogicException;

/**
 * An address of the user's own that hears, signed, about what happens in
 * their library. The address and its secret are credentials: encrypted at
 * rest, hidden from serialization, never back on the page.
 *
 * @property int $id
 * @property int $user_id
 * @property string $label
 * @property string $url
 * @property string $secret
 * @property Collection<int, WebhookEventGroup> $events
 * @property bool $enabled
 * @property DeliveryAnswer|null $last_failure
 * @property Carbon $created_at
 * @property-read User $user
 */
class OutgoingWebhook extends Model
{
    /** @use HasFactory<OutgoingWebhookFactory> */
    use HasFactory;

    /** How long a webhook's name may be. */
    public const LABEL_MAX = 60;

    /** How many webhooks one account may keep: each is a delivery for every change. */
    public const PER_USER = 10;

    /** How long an address may be. */
    public const URL_MAX = 2048;

    protected $guarded = [];

    /** @var list<string> */
    protected $hidden = ['url', 'secret'];

    /** @var array<string, mixed> */
    protected $attributes = ['enabled' => true];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'url' => 'encrypted',
            'secret' => 'encrypted',
            'events' => AsEnumCollection::of(WebhookEventGroup::class),
            'enabled' => 'boolean',
            'last_failure' => DeliveryAnswer::class,
        ];
    }

    /** The name the user gave, or the host when they left it empty. */
    public function nameFrom(?string $label): string
    {
        return $label === null || $label === '' ? $this->host() : $label;
    }

    /** Where it posts, without the path and query, which may hold a token. */
    public function host(): string
    {
        $host = parse_url($this->url, PHP_URL_HOST);
        $port = parse_url($this->url, PHP_URL_PORT);

        if (! is_string($host) || $host === '') {
            throw new LogicException('A webhook address is checked to have a host when it is saved.');
        }

        return $host.(is_int($port) ? ":{$port}" : '');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
