<?php

namespace App\Integrations\Connections\Data;

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

    /** The `CURLOPT_RESOLVE` entry that holds the request to the approved IP. */
    public function curlResolve(): string
    {
        $ip = str_contains($this->ip, ':') ? "[{$this->ip}]" : $this->ip;

        return "{$this->host}:{$this->port}:{$ip}";
    }
}
