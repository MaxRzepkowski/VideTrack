<?php

namespace App\Integrations\WebPush;

use LogicException;
use Minishlink\WebPush\SubscriptionInterface;
use Minishlink\WebPush\WebPush;
use Psr\Http\Message\RequestInterface;

/**
 * The library's encryption and signing without its sending: a push leaves
 * through the instance's own HTTP client, as every other delivery does.
 */
final class PushRequests extends WebPush
{
    public function __construct(VapidCredentials $vapid, int $ttlSeconds)
    {
        parent::__construct(
            ['VAPID' => ['subject' => $vapid->subject, 'publicKey' => $vapid->publicKey, 'privateKey' => $vapid->privateKey]],
            ['TTL' => $ttlSeconds],
        );
    }

    /** The signed request carrying the payload, encrypted to the browser's keys. */
    public function for(SubscriptionInterface $subscription, string $payload): RequestInterface
    {
        $this->queueNotification($subscription, $payload);
        $request = $this->prepare($this->notifications ?? [])[0] ?? null;
        $this->notifications = null;

        return $request instanceof RequestInterface ? $request : throw new LogicException('A queued push makes one request.');
    }
}
