<?php

namespace App\Integrations\WebPush;

use App\Support\Payload;
use Illuminate\Support\Facades\File;
use Minishlink\WebPush\VAPID;
use RuntimeException;

/**
 * Where the instance's push key pair comes from: the one the operator gave in
 * the environment, else the one the first start made and kept on disk next
 * to the database. A pair is made once: a new one leaves every browser
 * subscribed with the old one to be switched on again.
 */
final readonly class VapidKeys
{
    private const PUBLIC = 'publicKey';

    private const PRIVATE = 'privateKey';

    /** The pair in use; null while the instance has none. */
    public function current(): ?VapidCredentials
    {
        $config = Payload::map(config('tracker.webpush'));
        $public = Payload::string($config, 'public_key');
        $private = Payload::string($config, 'private_key');

        if ($public !== null && $private !== null) {
            return new VapidCredentials($public, $private, $this->subject());
        }

        $path = $this->path();

        if (! is_file($path)) {
            return null;
        }

        $kept = Payload::map(json_decode(File::get($path), true, flags: JSON_THROW_ON_ERROR));
        $public = Payload::string($kept, self::PUBLIC);
        $private = Payload::string($kept, self::PRIVATE);

        if ($public === null || $private === null) {
            throw new RuntimeException("The web push keys in {$path} are incomplete.");
        }

        return new VapidCredentials($public, $private, $this->subject());
    }

    /**
     * Makes a pair and keeps it on disk, readable by the instance alone,
     * unless the instance already has one.
     *
     * @return string|null where the new pair was kept; null when none was made
     */
    public function makeIfMissing(): ?string
    {
        if ($this->current() !== null) {
            return null;
        }

        $path = $this->path();
        $pair = VAPID::createVapidKeys();

        File::ensureDirectoryExists(dirname($path));
        File::put($path, json_encode([self::PUBLIC => $pair['publicKey'], self::PRIVATE => $pair['privateKey']], JSON_THROW_ON_ERROR));
        File::chmod($path, 0600);

        return $path;
    }

    /**
     * What the operator named, else the instance's own address when a push
     * service accepts it (Apple takes only https: and mailto:), else the
     * address of the source, which is always https.
     */
    private function subject(): string
    {
        $named = Payload::string(Payload::map(config('tracker.webpush')), 'subject');
        $url = config()->string('app.url');

        return $named ?? (str_starts_with($url, 'https://') ? $url : config()->string('tracker.source_url'));
    }

    private function path(): string
    {
        return config()->string('tracker.webpush.keys_path');
    }
}
