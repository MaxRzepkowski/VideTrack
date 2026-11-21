<?php

namespace App\Integrations\Kavita;

use App\Integrations\Connections\OwnServerKeyService;

/**
 * Connecting a Kavita server: its address and an auth key of the user.
 */
class KavitaService extends OwnServerKeyService
{
    public const CODE = 'kavita';

    public function __construct(KavitaClient $client)
    {
        parent::__construct($client);
    }

    public function code(): string
    {
        return self::CODE;
    }

    protected function exampleAddress(): string
    {
        return 'http://192.168.1.10:5000';
    }

    protected function keyLabel(): string
    {
        return __('import.auth_key');
    }

    protected function keyHelp(): string
    {
        return __('import.in_kavita_user_settings_manage_auth_keys');
    }
}
