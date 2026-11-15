<?php

namespace App\Http\Resources\Api\V1;

use App\Models\User;
use Illuminate\Http\Request;

/**
 * The account the token belongs to — its own profile, so the email is part
 * of it. Never used to render anybody else's account.
 *
 * @mixin User
 */
class UserResource extends ApiResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'username' => $this->username,
            'name' => $this->name,
            'email' => $this->email,
            'locale' => $this->locale,
            'avatar' => $this->avatar,
            'isAdmin' => $this->isAdmin(),
            'createdAt' => $this->created_at?->toIso8601String(),
        ];
    }
}
