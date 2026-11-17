<?php

namespace App\Integrations\Apprise;

/**
 * What a part of an Apprise URL holds, as the container's schema types it.
 * A list of them is a flag on the token, not a type of its own.
 */
enum AppriseTokenType: string
{
    case Text = 'string';
    case Number = 'int';
    case Email = 'email';
    case Choice = 'choice:string';
}
