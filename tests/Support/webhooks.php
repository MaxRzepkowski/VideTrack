<?php

use Database\Factories\OutgoingWebhookFactory;

// Outgoing webhooks: the secret a factory-made webhook signs with.

const WEBHOOK_SECRET = OutgoingWebhookFactory::SECRET;
