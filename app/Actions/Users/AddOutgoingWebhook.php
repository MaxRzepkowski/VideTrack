<?php

namespace App\Actions\Users;

use App\Integrations\OutgoingWebhooks\Data\OutgoingWebhookSettings;
use App\Models\OutgoingWebhook;
use App\Models\User;
use InvalidArgumentException;

/** A new address the user's library is told about, named after its host unless named. */
final readonly class AddOutgoingWebhook
{
    public function execute(User $user, OutgoingWebhookSettings $settings): OutgoingWebhook
    {
        $webhook = new OutgoingWebhook([
            'url' => $settings->url ?? throw new InvalidArgumentException('A new webhook needs its address.'),
            'secret' => $settings->secret ?? throw new InvalidArgumentException('A new webhook needs its secret.'),
            'events' => $settings->events ?? throw new InvalidArgumentException('A new webhook needs its events.'),
            ...($settings->enabled === null ? [] : ['enabled' => $settings->enabled]),
        ]);
        $webhook->label = $webhook->nameFrom($settings->label);
        $user->outgoingWebhooks()->save($webhook);

        return $webhook;
    }
}
