<?php

namespace App\Catalog\Contracts;

use App\Catalog\MatureContent;

/**
 * Capability: the provider files adult material, and its search, browsing
 * and studio or network catalogues keep to the MatureContent level they are
 * asked for. A provider without it files none and ignores the level.
 */
interface SupportsMatureFilter
{
    /**
     * The levels the provider can tell apart, mildest first, always with
     * MatureContent::Suggestive among them. Callers ask for one of these: a
     * level it cannot tell apart reads as the nearest one it can, which may
     * let in more than was asked.
     *
     * @return non-empty-list<MatureContent>
     */
    public function matureLevels(): array;
}
