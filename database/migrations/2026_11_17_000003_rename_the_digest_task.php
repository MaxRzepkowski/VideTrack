<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * The daily digest task is named after the channels it serves. Renaming its
 * past runs keeps the catch-up from taking the task for one that never ran.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('task_runs')->where('name', 'discord-digest')->update(['name' => 'channel-digest']);
    }

    public function down(): void
    {
        DB::table('task_runs')->where('name', 'channel-digest')->update(['name' => 'discord-digest']);
    }
};
