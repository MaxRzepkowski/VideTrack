<?php

namespace App\Catalog\Contracts;

use App\Catalog\Data\EventData;
use App\Catalog\ItemNotFoundException;
use Carbon\CarbonImmutable;

/**
 * Capability: the provider lists the showcases and shows of its medium
 * (Summer Game Fest, a Nintendo Direct) with the titles they showed.
 */
interface SupportsEvents
{
    /**
     * Every event starting from `$since` on, those still ahead included.
     *
     * @return list<EventData>
     */
    public function events(CarbonImmutable $since): array;

    /**
     * One event with its titles, however long ago it aired.
     *
     * @throws ItemNotFoundException
     */
    public function event(string $ref): EventData;

    /**
     * Every event that showed a title, the latest first, without their titles.
     *
     * @return list<EventData>
     */
    public function eventsShowing(string $ref): array;
}
