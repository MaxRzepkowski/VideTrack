<?php

namespace App\Notifications\Events;

use App\Notifications\MediaEventType;
use App\Support\ServiceRegistry;

/**
 * Every kind of media event and the class that builds it and words it, by
 * {@see MediaEventType}.
 *
 * @extends ServiceRegistry<EventKind>
 */
final class EventKinds extends ServiceRegistry
{
    /** The class of a stored event's kind; null for one this version does not know. */
    public function of(?MediaEventType $type): ?EventKind
    {
        return $type === null ? null : $this->find($type->value);
    }

    protected function kind(): string
    {
        return 'kind of media event';
    }
}
