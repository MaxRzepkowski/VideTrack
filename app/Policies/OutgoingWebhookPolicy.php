<?php

namespace App\Policies;

use App\Models\OutgoingWebhook;
use App\Models\User;
use Illuminate\Auth\Access\Response;

final class OutgoingWebhookPolicy
{
    use OwnsResource;

    public function update(User $user, OutgoingWebhook $webhook): Response
    {
        return $this->owner($user, $webhook);
    }

    public function delete(User $user, OutgoingWebhook $webhook): Response
    {
        return $this->owner($user, $webhook);
    }
}
