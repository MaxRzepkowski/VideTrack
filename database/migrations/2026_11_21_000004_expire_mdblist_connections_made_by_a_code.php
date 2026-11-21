<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * An MDBList connection holds the user's own API key. One made with a code
 * through an operator's app holds that app's tokens instead, which the
 * import does not read: it is expired, so its card asks the user to connect
 * again.
 *
 * down() only comes close: it gives back every MDBList connection expired
 * for this reason, so one MDBList had already stopped honouring before
 * comes back too (its next run expires it again). Tokens are as they were.
 */
return new class extends Migration
{
    private const REASON = 'import.mdblist_stopped_accepting_this_connection';

    public function up(): void
    {
        DB::table('service_connections')->where('service', 'mdblist')->where('status', 'active')
            ->update(['status' => 'expired', 'last_error' => self::REASON]);
    }

    public function down(): void
    {
        DB::table('service_connections')->where('service', 'mdblist')->where('status', 'expired')->where('last_error', self::REASON)
            ->update(['status' => 'active', 'last_error' => null]);
    }
};
