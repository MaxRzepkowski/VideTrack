<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * A Simkl connection holds the id of the user's own Simkl app with its
 * tokens. One without that id holds tokens of an app the code does not
 * name, so it cannot be used: it is deleted, and the card offers to connect
 * as for an account never connected.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('service_connections')->where('service', 'simkl')->delete();
    }

    /**
     * Does not bring back the deleted Simkl connections; their users connect
     * them again. The schema itself is untouched.
     */
    public function down(): void {}
};
