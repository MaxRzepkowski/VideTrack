<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Why a server the user runs stopped taking its key is worded the same for
 * every service. Rewording the Tracearr connections that expired before
 * keeps their cards from showing a translation key no language has.
 */
return new class extends Migration
{
    private const OLD = 'import.tracearr_stopped_accepting_this_api_key';

    private const NEW = 'import.the_server_stopped_accepting_this_key';

    public function up(): void
    {
        DB::table('service_connections')->where('service', 'tracearr')->where('last_error', self::OLD)->update(['last_error' => self::NEW]);
    }

    public function down(): void
    {
        DB::table('service_connections')->where('service', 'tracearr')->where('last_error', self::NEW)->update(['last_error' => self::OLD]);
    }
};
