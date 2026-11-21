<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * A MangaBaka connection holds the user's personal token. One made through
 * an operator's OAuth app holds that app's tokens instead, which the import
 * does not read. Every MangaBaka connection there is when this runs was made
 * so, since the code that makes the other kind runs only after: all are
 * deleted, and the card offers to connect as for an account never connected.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('service_connections')->where('service', 'mangabaka')->delete();
    }

    /**
     * Does not bring back the deleted connections; their users connect them
     * again. The schema itself is untouched.
     */
    public function down(): void {}
};
