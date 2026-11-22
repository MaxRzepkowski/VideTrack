<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Forgets when each title was last asked what it is tied to, so the
 * relations task asks about every one again, works in other media and
 * manga, games, books and comics included. The ties stored so far stay
 * until each title's turn comes.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('title_relation_checks')->delete();
    }

    /**
     * Does not bring back when each title was asked; they are asked again in
     * turn whichever way the schema runs.
     */
    public function down(): void {}
};
