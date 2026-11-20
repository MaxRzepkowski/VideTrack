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

    /** The badge's words in a language; the same keys the cards use. */
    public function label(?string $locale = null): string
    {
        return match ($this) {
            self::Dlc => __('media.dlc', [], $locale),
            self::Expansion => __('media.expansion', [], $locale),
            self::Bundle => __('media.bundle', [], $locale),
            self::StandaloneExpansion => __('media.standalone_expansion', [], $locale),
            self::Mod => __('media.mod', [], $locale),
            self::Episode => __('media.episode', [], $locale),
            self::Season => __('media.season', [], $locale),
            self::Remake => __('media.remake', [], $locale),
            self::Remaster => __('media.remaster', [], $locale),
            self::Expanded => __('media.expanded_game', [], $locale),
            self::Port => __('media.port', [], $locale),
            self::Fork => __('media.fork', [], $locale),
            self::Pack => __('media.pack', [], $locale),
            self::Update => __('media.update', [], $locale),
        };
    }
}
