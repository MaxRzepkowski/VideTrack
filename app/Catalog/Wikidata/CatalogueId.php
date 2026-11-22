<?php

namespace App\Catalog\Wikidata;

use App\Catalog\MediaKind;
use App\Catalog\Relations\CatalogWork;

/**
 * An id Wikidata keeps of a work in one of the catalogues our providers read:
 * how a work Wikidata names is found in our catalogue. Each case is also the
 * query variable the id is bound to.
 */
enum CatalogueId: string
{
    case Movie = 'movie';
    case Show = 'show';

    /** The kinds of screen work. */
    public const SCREEN = [self::Movie, self::Show];

    public function kind(): MediaKind
    {
        return match ($this) {
            self::Movie => MediaKind::Movie,
            self::Show => MediaKind::Show,
        };
    }

    /** The catalogue the id belongs to, as the id resolver reads it. */
    public function catalogue(): string
    {
        return match ($this) {
            self::Movie, self::Show => 'tmdb',
        };
    }

    /** The path from a work to the id. */
    private function path(): string
    {
        return match ($this) {
            self::Movie => 'wdt:P4947',
            self::Show => 'wdt:P4983',
        };
    }

    /**
     * The variables to select: `?movie ?show`.
     *
     * @param  list<self>  $ids
     */
    public static function variables(array $ids): string
    {
        return implode(' ', array_map(fn (self $id): string => "?{$id->value}", $ids));
    }

    /**
     * The optional patterns binding each id of the work `?{$work}`.
     *
     * @param  list<self>  $ids
     */
    public static function patterns(string $work, array $ids): string
    {
        return implode("\n", array_map(fn (self $id): string => "OPTIONAL { ?{$work} {$id->path()} ?{$id->value} . }", $ids));
    }

    /**
     * The condition that the work has one of the ids at all.
     *
     * @param  list<self>  $ids
     */
    public static function anyBound(array $ids): string
    {
        return implode(' || ', array_map(fn (self $id): string => "BOUND(?{$id->value})", $ids));
    }

    /**
     * The works a result row's ids name, one per id bound to a number.
     *
     * @param  array<array-key, mixed>  $row
     * @param  list<self>  $ids
     * @return array<string, CatalogWork> by kind and id
     */
    public static function works(array $row, array $ids): array
    {
        $works = [];

        foreach ($ids as $id) {
            $value = SparqlRow::value($row, $id->value);

            if ($value !== null && ctype_digit($value)) {
                $works["{$id->kind()->value}:{$value}"] = new CatalogWork($id->kind(), [$id->catalogue() => $value]);
            }
        }

        return $works;
    }
}
