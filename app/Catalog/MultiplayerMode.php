<?php

namespace App\Catalog;

use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * A way a game is played together. "Online" and "local" without "co-op" are
 * the plain multiplayer of either kind; a provider maps its own flags onto these.
 */
#[TypeScript]
enum MultiplayerMode: string
{
    case OnlineCoop = 'online_coop';
    case LocalCoop = 'local_coop';
    case LanCoop = 'lan_coop';
    case CampaignCoop = 'campaign_coop';
    case DropIn = 'drop_in';
    case SplitScreen = 'split_screen';
    case Online = 'online';
    case Local = 'local';
}
