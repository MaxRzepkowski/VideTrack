<?php

namespace App\Casts;

use App\Accounts\Preferences\UserPreferences;
use App\Support\Payload;
use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;

/**
 * `users.preferences`: the user's choices in JSON, {@see UserPreferences} on
 * the model. Takes a plain array of choices too — what a factory or a test
 * sets. No choices store NULL.
 *
 * @implements CastsAttributes<UserPreferences, UserPreferences|array<string, mixed>|null>
 */
final class AsUserPreferences implements CastsAttributes
{
    /**
     * @param  array<string, mixed>  $attributes
     */
    public function get(Model $model, string $key, mixed $value, array $attributes): UserPreferences
    {
        return UserPreferences::fromStored(Payload::named(is_string($value) ? json_decode($value, true) : null));
    }

    /**
     * @param  UserPreferences|array<string, mixed>|null  $value
     * @param  array<string, mixed>  $attributes
     */
    public function set(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        $stored = $value instanceof UserPreferences ? $value->toStored() : ($value ?? []);

        return $stored === [] ? null : json_encode($stored, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }
}
