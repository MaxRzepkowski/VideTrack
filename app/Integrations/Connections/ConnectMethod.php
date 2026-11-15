<?php

namespace App\Integrations\Connections;

use App\Integrations\Connections\Contracts\AuthorizesByDevice;
use App\Integrations\Connections\Contracts\ConnectableService;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * How the user connects a service: approving a code on the service's site,
 * or typing what it gave them (an address and a key) into a form.
 */
#[TypeScript]
enum ConnectMethod: string
{
    case Device = 'device';
    case Form = 'form';

    public static function of(ConnectableService $service): self
    {
        return $service instanceof AuthorizesByDevice ? self::Device : self::Form;
    }
}
