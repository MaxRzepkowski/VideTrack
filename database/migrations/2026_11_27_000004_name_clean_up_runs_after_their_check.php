<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Past runs of the expired-data clean-up take the name of its maintenance
 * check, so the task history reads them as its own.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('task_runs')->where('name', 'cleanup')->update(['name' => 'check-expired-data']);
    }

    /** Gives every run of the check the old name, those it wrote after the rename too. */
    public function down(): void
    {
        DB::table('task_runs')->where('name', 'check-expired-data')->update(['name' => 'cleanup']);
    }
};
