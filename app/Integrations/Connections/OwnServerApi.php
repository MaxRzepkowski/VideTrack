<?php

namespace App\Integrations\Connections;

use App\Integrations\Connections\Data\PinnedAddress;
use App\Models\ServiceConnection;
use App\Models\User;
use LogicException;

/**
 * The API of a server the user runs (Tracearr, Audiobookshelf…), reached
 * through `OwnServerClient`: where the address the user typed is checked,
 * and the stored one again before every run. A service's client adds its
 * requests and its way of signing in.
 */
abstract class OwnServerApi
{
    public function __construct(protected readonly OwnServerClient $server) {}

    /** The service's own name, for the messages that name it. */
    abstract public function name(): string;

    /**
     * The API's path a user may paste with the address, as an unanchored
     * PCRE fragment without `#` (Tracearr's is `/api(/v\d+(/public)?)?`).
     */
    abstract protected function apiPathPattern(): string;

    /**
     * The address the user typed, at its base and checked for the owner.
     *
     * @throws UnusableAddress
     */
    public function pin(string $typed, User $owner): PinnedAddress
    {
        return $this->server->pinTyped($typed, $this->apiPathPattern(), $owner);
    }

    /** The key the connection keeps; one without it was never made by a form that asks for it. */
    protected function key(ServiceConnection $connection): string
    {
        return $connection->credential('key') ?? throw new LogicException("A {$this->name()} connection without its key.");
    }

    /**
     * The connection's address, checked again for this run.
     *
     * @throws ConnectionExpired|OwnServerUnavailable
     */
    public function address(ServiceConnection $connection): PinnedAddress
    {
        return $this->server->address($connection, $this->name());
    }
}
