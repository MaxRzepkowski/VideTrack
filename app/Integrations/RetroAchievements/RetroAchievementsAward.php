<?php

namespace App\Integrations\RetroAchievements;

/**
 * The highest award a player holds for a game (`HighestAwardKind`). Every
 * one of them means the game was finished: beaten in softcore or hardcore
 * mode, or every achievement earned (completed in softcore, mastered in
 * hardcore).
 */
enum RetroAchievementsAward: string
{
    case Mastered = 'mastered';
    case Completed = 'completed';
    case BeatenHardcore = 'beaten-hardcore';
    case BeatenSoftcore = 'beaten-softcore';
}
