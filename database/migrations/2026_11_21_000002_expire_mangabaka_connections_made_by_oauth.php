<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * A MangaBaka connection holds the user's personal token. One made through
 * an operator's OAuth app holds that app's tokens instead, which the import
 * does not read: it is expired, so its card asks the user to connect again.
 * down() only comes close: it gives back every MangaBaka connection expired
 * for this reason, so one MangaBaka had already stopped honouring before
 * comes back too (its next run expires it again). Tokens are as they were.
 */
return new class extends Migration
{
    private const REASON = 'import.mangabaka_stopped_accepting_this_connection';

    public function up(): void
    {
        DB::table('service_connections')->where('service', 'mangabaka')->where('status', 'active')
            ->update(['status' => 'expired', 'last_error' => self::REASON]);
    }

    public function down(): void
    {
        DB::table('service_connections')->where('service', 'mangabaka')->where('status', 'expired')->where('last_error', self::REASON)
            ->update(['status' => 'active', 'last_error' => null]);
    }
};
