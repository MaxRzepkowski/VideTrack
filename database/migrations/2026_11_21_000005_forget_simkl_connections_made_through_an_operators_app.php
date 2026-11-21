<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * A Simkl connection holds the id of the user's own Simkl app with its
 * tokens. One made through an operator's app holds no id, and its tokens
 * belong to an app the code does not name. Every Simkl connection there is
 * when this runs was made so, since the code that makes the other kind runs
 * only after: all are deleted, and the card offers to connect as for an
 * account never connected.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('service_connections')->where('service', 'simkl')->delete();
    }

    /**
     * Does not bring back the deleted connections; their users connect them
     * again. The schema itself is untouched.
     */
    public function down(): void {}
};
