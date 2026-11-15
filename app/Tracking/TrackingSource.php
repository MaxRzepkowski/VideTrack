<?php

namespace App\Tracking;

use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * Why a title is tracked: the one row of the tracking matrix it falls in. A
 * title can be on the backlog, watched and belled at once — it still falls in
 * exactly one row, the first that applies in {@see Data\TrackingFacts::source()},
 * and that row's toggle decides for it. The bell names the same row.
 */
#[TypeScript]
enum TrackingSource: string
{
    case OnHold = 'on_hold';
    case Dropped = 'dropped';
    case Backlog = 'backlog';
    case Activity = 'activity';
    case ManualFollow = 'followed';
    case CreatorFollow = 'creator';
}
