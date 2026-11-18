<?php

use App\Integrations\OutgoingWebhooks\WebhookSignature;
use Database\Factories\OutgoingWebhookFactory;
use Illuminate\Http\Client\Request;

// Outgoing webhooks: the secret a factory-made webhook signs with, and the check of its signature.

const WEBHOOK_SECRET = OutgoingWebhookFactory::SECRET;

/** Whether a request carries the Standard Webhooks signature its own id, time and body make under that secret. */
function signedByWebhook(Request $request): bool
{
    return $request->header('webhook-signature')[0] === WebhookSignature::sign(WEBHOOK_SECRET, $request->header('webhook-id')[0], (int) $request->header('webhook-timestamp')[0], $request->body());
}
