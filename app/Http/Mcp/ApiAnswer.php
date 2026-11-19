<?php

namespace App\Http\Mcp;

use App\Support\Payload;

/** What a route of API v1 answered: its status and its decoded JSON. */
final readonly class ApiAnswer
{
    public function __construct(
        public int $status,
        public mixed $body,
    ) {}

    public function succeeded(): bool
    {
        return $this->status < 300;
    }

    /** @return array<string, mixed> the top level of the answer, empty when it is not an object */
    public function object(): array
    {
        return Payload::named($this->body);
    }

    /** @return list<array<array-key, mixed>> the `data` list of a listing */
    public function items(): array
    {
        return Payload::list($this->object()['data'] ?? null);
    }
}
