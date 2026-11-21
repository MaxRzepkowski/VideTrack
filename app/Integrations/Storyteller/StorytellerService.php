<?php

namespace App\Integrations\Storyteller;

use App\Integrations\Connections\OwnServerKeyService;

/**
 * Connecting a Storyteller server: its address and an API token with full
 * access.
 */
class StorytellerService extends OwnServerKeyService
{
    public const CODE = 'storyteller';

    public function __construct(StorytellerClient $client)
    {
        parent::__construct($client);
    }

    public function code(): string
    {
        return self::CODE;
    }

    protected function exampleAddress(): string
    {
        return 'http://192.168.1.10:8001';
    }

    protected function keyLabel(): string
    {
        return __('import.api_token');
    }

    protected function keyHelp(): string
    {
        return __('import.in_storyteller_preferences_devices_tokens');
    }
}
