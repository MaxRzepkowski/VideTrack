<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Brings the query planner's statistics up to date. Without them SQLite
 * guesses how selective every index is, and on a large library a guess can
 * pick a slow plan. Cheap enough for every start: SQLite samples each table
 * and skips the ones whose statistics still fit.
 */
class DatabaseOptimizeCommand extends Command
{
    protected $signature = 'vide:database:optimize';

    protected $description = 'Bring the query planner statistics up to date';

    public function handle(): int
    {
        if (DB::connection()->getDriverName() !== 'sqlite') {
            $this->info('This database keeps its planner statistics itself; nothing to do.');

            return self::SUCCESS;
        }

        // 0x10000: every table, not only the ones this fresh connection has
        // queried; 0x02: analyze the tables that would benefit.
        DB::statement('PRAGMA optimize=0x10002');
        $this->info('The query planner statistics are up to date.');

        return self::SUCCESS;
    }
}
