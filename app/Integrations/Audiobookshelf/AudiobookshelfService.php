<?php

namespace App\Integrations\Audiobookshelf;

use App\Integrations\Connections\OwnServerKeyService;

/**
 * Connecting an Audiobookshelf server: its address and an API key that acts
 * as this user.
 */
class AudiobookshelfService extends OwnServerKeyService
{
    public const CODE = 'audiobookshelf';

    public function __construct(AudiobookshelfClient $client)
    {
        parent::__construct($client);
    }

    public function code(): string
    {
        return self::CODE;
    }

    protected function exampleAddress(): string
    {
        return 'http://192.168.1.10:13378';
    }

    protected function keyLabel(): string
    {
        return __('import.api_key');
    }

    protected function keyHelp(): string
    {
        return __('import.in_audiobookshelf_settings_api_keys');
    }
}
