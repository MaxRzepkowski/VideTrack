<?php

namespace App\Policies;

use App\Models\User;
use App\Models\WebhookEndpoint;
use Illuminate\Auth\Access\Response;

final class WebhookEndpointPolicy
{
    use OwnsResource;

    public function update(User $user, WebhookEndpoint $endpoint): Response
    {
        return $this->owner($user, $endpoint);
    }

    public function delete(User $user, WebhookEndpoint $endpoint): Response
    {
        return $this->owner($user, $endpoint);
    }
}
