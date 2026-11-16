<?php

namespace App\Models;

use Database\Factories\IdentityFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * An account at an identity provider its owner signs in with. Only who the
 * person is there (the provider's code and its id for them) and the address
 * it gave; never a token.
 *
 * @property int $id
 * @property int $user_id
 * @property string $provider code of the identity provider (IdentityProviderRegistry)
 * @property string $subject the person's id at the provider
 * @property string|null $email
 * @property Carbon $created_at
 * @property-read User $user
 */
class Identity extends Model
{
    /** @use HasFactory<IdentityFactory> */
    use HasFactory;

    protected $guarded = [];

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
