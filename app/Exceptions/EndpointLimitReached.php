<?php

namespace App\Exceptions;

/**
 * One account takes a limited number of addresses for one media server; the
 * server's card says so instead of offering another.
 */
final class EndpointLimitReached extends LibraryRefusal
{
    public function __construct(private readonly int $limit)
    {
        parent::__construct("At most {$limit} addresses for one server.");
    }

    public function field(): string
    {
        return 'source';
    }

    public function reason(): string
    {
        return trans_choice('integrations.up_to_count_addresses_for_one_server', $this->limit);
    }
}
