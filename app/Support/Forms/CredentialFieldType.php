<?php

namespace App\Support\Forms;

use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * How a field of a credentials form is typed in. A checkbox is an opt-in:
 * optional, and it travels as '1' or ''.
 */
#[TypeScript]
enum CredentialFieldType: string
{
    case Text = 'text';
    case Url = 'url';
    case Password = 'password';
    case Email = 'email';
    case Checkbox = 'checkbox';
}
