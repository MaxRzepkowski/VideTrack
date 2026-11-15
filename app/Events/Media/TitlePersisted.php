<?php

namespace App\Events\Media;

/**
 * A title's whole tree was stored from the provider because the library lacked
 * it or a part of it (a new title, or an episode it did not have yet). Not a
 * user's act: anything that learns more about a title from elsewhere (an air
 * schedule) starts from here.
 */
final readonly class TitlePersisted
{
    public function __construct(
        public int $itemId,
        public string $type,
    ) {}
}
