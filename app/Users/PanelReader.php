<?php

namespace App\Users;

use App\Models\User;

/**
 * Who reads a panel of a user view: its owner, or someone else. The list and
 * what sits under a card belong to the owner; a visitor brings their own
 * rating to sort by and their own library to hide what they know.
 */
enum PanelReader
{
    case Owner;
    case Visitor;

    public static function of(User $viewer, User $owner): self
    {
        return $viewer->is($owner) ? self::Owner : self::Visitor;
    }
}
