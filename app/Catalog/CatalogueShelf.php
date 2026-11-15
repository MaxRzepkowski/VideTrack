<?php

namespace App\Catalog;

use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * The shelf a title sits on in a company's catalogue — the page's content
 * filter, in the order the page offers it. Game companies shelve games, DLCs
 * and the rest; a film studio shelves films and shows.
 */
#[TypeScript]
enum CatalogueShelf: string
{
    case Game = 'game';
    case Dlc = 'dlc';
    case Other = 'other';
    case Movie = 'movie';
    case Show = 'show';
}
