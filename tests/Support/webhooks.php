<?php

use App\Integrations\OutgoingWebhooks\WebhookSignature;
use Illuminate\Http\Client\Request;

// Outgoing webhooks: the check of a message's signature.

/** Whether a request carries the Standard Webhooks signature its own id, time and body make under the secret. */
function signedByWebhook(Request $request, string $secret): bool
{
    return $request->header('webhook-signature')[0] === WebhookSignature::sign($secret, $request->header('webhook-id')[0], (int) $request->header('webhook-timestamp')[0], $request->body());
}
