<?php

namespace App\Tasks\Maintenance;

/**
 * Why a check runs: its window came round, or the admin asked for it — then
 * they want to see now what a fix changed, so a check asks again what it
 * would otherwise leave for later.
 */
enum RunCause: string
{
    case Window = 'window';
    case Admin = 'admin';
}
