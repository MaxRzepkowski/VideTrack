<?php

namespace App\Integrations\Connections;

use App\Integrations\Connections\Contracts\AuthorizesByDevice;
use App\Integrations\Connections\Contracts\AuthorizesByRedirect;
use App\Integrations\Connections\Contracts\ConnectableService;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * How the user connects a service: approving a code on the service's site,
 * approving on the site it sends them to and back, or typing what it gave
 * them (an address and a key) into a form.
 */
#[TypeScript]
enum ConnectMethod: string
{
    case Device = 'device';
    case Redirect = 'redirect';
    case Form = 'form';

    public static function of(ConnectableService $service): self
    {
        return match (true) {
            $service instanceof AuthorizesByDevice => self::Device,
            $service instanceof AuthorizesByRedirect => self::Redirect,
            default => self::Form,
        };
    }
}
