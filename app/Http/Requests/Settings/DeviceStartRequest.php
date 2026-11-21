<?php

namespace App\Http\Requests\Settings;

use App\Integrations\Connections\Contracts\AuthorizesByDevice;
use LogicException;

/**
 * What the user typed on the card to start a device flow, validated as any
 * connect form is.
 */
class DeviceStartRequest extends ConnectionFieldsRequest
{
    /** The service, connected by a device code the form starts. */
    public function device(): AuthorizesByDevice
    {
        $connectable = $this->connectable();

        return $connectable instanceof AuthorizesByDevice ? $connectable : throw new LogicException('A device start for a service that connects no device.');
    }

    protected function capability(): string
    {
        return AuthorizesByDevice::class;
    }
}
