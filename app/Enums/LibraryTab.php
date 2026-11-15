<?php

namespace App\Enums;

/** The four ways the API walks a user's library. */
enum LibraryTab: string
{
    case History = 'history';
    case Ratings = 'ratings';
    case Favorites = 'favorites';
    case Progress = 'progress';
}
