<?php

namespace App\Catalog\Data;

/** How readers of one language know a title: its name and its poster there. */
final readonly class LocalizedTitle
{
    public function __construct(
        public string $title,
        public ?string $posterPath = null,
    ) {}
}
