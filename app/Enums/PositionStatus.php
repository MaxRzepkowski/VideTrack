<?php

namespace App\Enums;

use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * The status a position shows: its open attempt — paused, or being played —
 * or the drop that closed the last one. Only the open ones are stored
 * ({@see MediaUserStatus}); a drop is a play.
 */
#[TypeScript]
enum PositionStatus: string
{
    case OnHold = 'on_hold';
    case InProgress = 'in_progress';
    case Dropped = 'dropped';

    public static function ofOpen(MediaUserStatus $status): self
    {
        return match ($status) {
            MediaUserStatus::OnHold => self::OnHold,
            MediaUserStatus::InProgress => self::InProgress,
        };
    }
}
