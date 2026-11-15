<?php

namespace App\Actions\Users;

use App\Models\User;
use App\Support\AvatarImage;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * The account's portrait, replaced or taken away. An upload is re-encoded,
 * never published as it came ({@see AvatarImage}); the file it replaces is
 * deleted once the account no longer points at it.
 */
final readonly class SetAvatar
{
    /**
     * @param  string|null  $upload  the uploaded file on disk; null removes the avatar
     */
    public function execute(User $user, ?string $upload): void
    {
        $previous = $user->avatar_path;
        $path = null;

        if ($upload !== null) {
            $path = 'avatars/'.Str::uuid().'.webp';
            Storage::disk('public')->put($path, AvatarImage::encode($upload));
        }

        $user->forceFill(['avatar_path' => $path])->save();

        if ($previous !== null && $previous !== $path) {
            Storage::disk('public')->delete($previous);
        }
    }
}
