<?php

namespace App\Accounts\Identity;

/** Why a sign-in through a provider, or a change to how one signs in, was refused. */
enum IdentityRefusal: string
{
    case Blocked = 'blocked';
    /** Nobody here goes with this identity, and it may not make an account. */
    case NoAccount = 'no_account';
    /** An account has this address; joining them on the address alone is off. */
    case EmailTaken = 'email_taken';
    /** The same, where no password signs in, so its owner cannot connect the provider themselves. */
    case EmailTakenWithoutPassword = 'email_taken_without_password';
    /** A new account needs an address the provider vouches for. */
    case EmailUnverified = 'email_unverified';
    case LinkedElsewhere = 'linked_elsewhere';
    case AlreadyConnected = 'already_connected';
    case LastSignInMethod = 'last_sign_in_method';
    /** The way back carried no sign-in this session started (an old tab, an expired session). */
    case Interrupted = 'interrupted';
    case Cancelled = 'cancelled';

    public function reason(): string
    {
        return match ($this) {
            self::Blocked => trans('auth.blocked'),
            self::NoAccount => trans('account.identity_no_account'),
            self::EmailTaken => trans('account.identity_email_taken'),
            self::EmailTakenWithoutPassword => trans('account.identity_email_taken_without_password'),
            self::EmailUnverified => trans('account.identity_email_unverified'),
            self::LinkedElsewhere => trans('account.identity_linked_elsewhere'),
            self::AlreadyConnected => trans('account.identity_already_connected'),
            self::LastSignInMethod => trans('account.identity_last_sign_in_method'),
            self::Interrupted => trans('account.identity_interrupted'),
            self::Cancelled => trans('account.identity_cancelled'),
        };
    }
}
