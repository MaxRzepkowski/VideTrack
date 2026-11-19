<?php

namespace App\Integrations\WebPush;

use Illuminate\Console\Command;

/**
 * Makes the instance's push key pair on its first start, so the operator has
 * nothing to generate. Safe to run on every start: a pair already given or
 * kept stays as it is.
 */
class WebPushKeysCommand extends Command
{
    protected $signature = 'vide:webpush:keys';

    protected $description = 'Make the key pair web push is signed with, unless the instance has one';

    public function handle(VapidKeys $keys): int
    {
        $path = $keys->makeIfMissing();

        if ($path !== null) {
            $this->info("Made the web push keys in {$path}; back them up with the database.");
        }

        return self::SUCCESS;
    }
}
