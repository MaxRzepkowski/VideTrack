<?php

namespace App\Actions\Users;

use App\Accounts\Identity\Data\IdentityClaims;
use App\Accounts\Identity\IdentityRefusal;
use App\Accounts\Identity\IdentityRefused;
use App\Accounts\PasswordLogin;
use App\Accounts\Registration;
use App\Accounts\Username;
use App\Models\Identity;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * The account a person the provider vouches for signs in to: the one the
 * identity is linked to, or a new one while sign-up is open. An address
 * never joins an identity to an account: nobody here confirms the address
 * an account was given, so whoever typed it in would get the next person
 * who signs in with it. The owner connects the provider from settings.
 */
final readonly class SignInWithIdentity
{
    /** What a username is made of when the person's handle and address leave too little. */
    private const USERNAME_FALLBACK = 'user';

    public function __construct(
        private LinkIdentity $link,
        private RegisterAccount $register,
        private Registration $registration,
        private PasswordLogin $passwordLogin,
    ) {}

    /** @throws IdentityRefused */
    public function execute(IdentityClaims $claims): User
    {
        return DB::transaction(function () use ($claims): User {
            $known = Identity::query()
                ->with('user')
                ->where('provider', $claims->provider)
                ->where('subject', $claims->subject)
                ->first();

            if ($known !== null) {
                // What settings shows follows the address the provider has now.
                $known->update(['email' => $claims->email]);

                return $this->admitted($known->user);
            }

            // Accounts keep their address in lower case (the sign-up form asks
            // for it), and a provider may send it as the person typed it.
            $address = $claims->email === null ? null : Str::lower($claims->email);
            $owner = $address === null ? null : User::query()->where('email', $address)->first();

            if ($owner !== null) {
                throw new IdentityRefused($this->passwordLogin->isEnabled() ? IdentityRefusal::EmailTaken : IdentityRefusal::EmailTakenWithoutPassword);
            }

            if (! $this->registration->isOpen()) {
                throw new IdentityRefused(IdentityRefusal::NoAccount);
            }

            // Password resets go to this address, so an unchecked one would
            // hand the account to whoever owns the mailbox.
            $email = $claims->emailVerified && $address !== null ? $address : throw new IdentityRefused(IdentityRefusal::EmailUnverified);
            $username = Username::firstFree($claims->username ?? str($email)->before('@')->toString(), self::USERNAME_FALLBACK);

            $user = $this->register->execute(filled($claims->name) ? $claims->name : $username, $username, $email, null);
            $this->link->execute($user, $claims);

            return $user;
        });
    }

    /** @throws IdentityRefused */
    private function admitted(User $user): User
    {
        return $user->isBlocked() ? throw new IdentityRefused(IdentityRefusal::Blocked) : $user;
    }
}
