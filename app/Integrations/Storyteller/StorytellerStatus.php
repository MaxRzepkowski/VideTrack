<?php

namespace App\Integrations\Storyteller;

/**
 * The reading statuses of a Storyteller library that say something of the
 * user (`status.name`). "To read", the default every book starts with, and a
 * status the user made up are no reading state, so they read as none.
 */
enum StorytellerStatus: string
{
    case Reading = 'Reading';
    case Read = 'Read';
}
