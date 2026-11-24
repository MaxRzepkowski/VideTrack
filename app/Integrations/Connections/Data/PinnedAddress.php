<?php

namespace App\Integrations\Connections\Data;

use Illuminate\Http\Client\PendingRequest;

/**
 * An address a user typed, checked, with the IP the check approved. Requests
 * go to that IP, so a name that resolves differently a moment later (DNS
 * rebinding) cannot move them somewhere the check never saw.
 */
final readonly class PinnedAddress
{
    public function __construct(
        public string $url,
        public string $host,
        public int $port,
        public string $ip,
    ) {}

    /**
     * $request held to the approved IP, with no redirect followed: a
     * redirect would lead to an address the check never saw.
     */
    public function hold(PendingRequest $request): PendingRequest
    {
        return $request->withoutRedirecting()
            ->withOptions(['curl' => [CURLOPT_RESOLVE => [$this->curlResolve()]]]);
    }

    /** The `CURLOPT_RESOLVE` entry that holds the request to the approved IP. */
    private function curlResolve(): string
    {
        $ip = str_contains($this->ip, ':') ? "[{$this->ip}]" : $this->ip;

        return "{$this->host}:{$this->port}:{$ip}";
    }
}
