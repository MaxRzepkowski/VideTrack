<?php

namespace App\Actions\Users;

use App\Models\OutgoingWebhook;

/** A webhook removed: nothing is sent to its address any more, and its secret is gone with it. */
final readonly class RemoveOutgoingWebhook
{
    public function execute(OutgoingWebhook $webhook): void
    {
        $webhook->delete();
    }
}
