<?php

namespace App\Catalog;

use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * A shop a game is sold in, or a subscription it is played through. The
 * values are the keys the page draws each brand by; a provider maps its own
 * names onto these.
 */
#[TypeScript]
enum GameStore: string
{
    case Steam = 'steam';
    case Gog = 'gog';
    case EpicGames = 'epicgames';
    case PlayStation = 'playstation';
    case Xbox = 'xbox';
    case Nintendo = 'nintendo';
    case Itch = 'itch';
    case AppStore = 'appstore';
    case GooglePlay = 'googleplay';
    case GamePass = 'gamepass';
    case Utomik = 'utomik';

    /** Played for a monthly fee rather than bought: a game can leave it. */
    public function isSubscription(): bool
    {
        return match ($this) {
            self::GamePass, self::Utomik => true,
            default => false,
        };
    }
}
