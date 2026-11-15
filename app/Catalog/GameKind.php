<?php

namespace App\Catalog;

use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * What kind of release a game card stands for, when it is not a game of its
 * own: the badge its card wears (the page words it). A provider maps its own
 * codes onto these.
 */
#[TypeScript]
enum GameKind: string
{
    case Dlc = 'dlc';
    case Expansion = 'expansion';
    case Bundle = 'bundle';
    case StandaloneExpansion = 'standalone_expansion';
    case Mod = 'mod';
    case Episode = 'episode';
    case Season = 'season';
    case Remake = 'remake';
    case Remaster = 'remaster';
    case Expanded = 'expanded';
    case Port = 'port';
    case Fork = 'fork';
    case Pack = 'pack';
    case Update = 'update';
}
