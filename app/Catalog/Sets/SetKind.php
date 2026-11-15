<?php

namespace App\Catalog\Sets;

/**
 * What a set of titles is at its provider, which is also how it is fetched.
 */
enum SetKind: string
{
    /** The parts of a provider's collection (a film saga). */
    case Collection = 'collection';

    /** Everything a person is credited on, a row per credit. */
    case Filmography = 'filmography';

    /** The top of a provider's chart ({@see ChartRef}), of everything or of a span of years. */
    case Chart = 'chart';

    /**
     * Days a copy stands before the provider is asked again. A chart moves
     * as new titles gather votes; a collection or a filmography gains a
     * title a few times a year.
     */
    public function freshDays(): int
    {
        return match ($this) {
            self::Chart => 7,
            self::Collection, self::Filmography => 30,
        };
    }
}
