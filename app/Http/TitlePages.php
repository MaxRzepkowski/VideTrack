<?php

namespace App\Http;

use Illuminate\Support\Facades\Route;

/**
 * Where a title's page lives: a type with a page of its own has a route named
 * after it (`movie.details`); any other type shares the one title page, its
 * code in the path. A new type is a module, not a route.
 */
final class TitlePages
{
    public static function hasOwnPage(string $type): bool
    {
        return Route::has("{$type}.details");
    }

    /** The page of a root title, as a path. */
    public static function path(string $type, string $ref): string
    {
        return self::hasOwnPage($type)
            ? route("{$type}.details", ['ref' => $ref], absolute: false)
            : route('title.details', ['type' => $type, 'ref' => $ref], absolute: false);
    }
}
