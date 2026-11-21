<?php

namespace App\Integrations\RetroAchievements;

use App\Support\ServiceUnavailable;

/**
 * RetroAchievements could not be read this time — out of reach or failing
 * on its side. Asking again later may work.
 */
final class RetroAchievementsUnavailable extends ServiceUnavailable {}
