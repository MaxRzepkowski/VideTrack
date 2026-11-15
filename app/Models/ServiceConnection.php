<?php

namespace App\Models;

use App\Integrations\Connections\ConnectionStatus;
use Database\Factories\ServiceConnectionFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A user's authorized link to an outside service the instance imports from.
 * Credentials are encrypted at rest and hidden from serialization — the
 * import page learns the account label and the status, never a token.
 *
 * @property int $id
 * @property int $user_id
 * @property string $service connectable service code ("simkl", …)
 * @property array<string, mixed> $credentials
 * @property array<string, mixed>|null $settings
 * @property array<string, mixed>|null $state
 * @property ConnectionStatus $status
 * @property string|null $account_label
 * @property bool $auto_import
 * @property Carbon|null $last_imported_at
 * @property string|null $last_error
 * @property Carbon $created_at
 * @property-read User $user
 */
class ServiceConnection extends Model
{
    /** @use HasFactory<ServiceConnectionFactory> */
    use HasFactory;

    protected $guarded = [];

    /** @var list<string> */
    protected $hidden = ['credentials'];

    /**
     * The column's default, so a connection just created says it too: a new
     * account is not imported on its own until the user asks.
     *
     * @var array<string, mixed>
     */
    protected $attributes = ['auto_import' => false];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'credentials' => 'encrypted:array',
            'settings' => 'array',
            'state' => 'array',
            'status' => ConnectionStatus::class,
            'auto_import' => 'boolean',
            'last_imported_at' => 'datetime',
        ];
    }

    public function credential(string $key): ?string
    {
        $value = $this->credentials[$key] ?? null;

        return is_scalar($value) ? (string) $value : null;
    }

    public function setting(string $key): ?string
    {
        $value = $this->settings[$key] ?? null;

        return is_scalar($value) ? (string) $value : null;
    }

    public function stateValue(string $key): ?string
    {
        $value = $this->state[$key] ?? null;

        return is_scalar($value) ? (string) $value : null;
    }

    public function isActive(): bool
    {
        return $this->status === ConnectionStatus::Active;
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
