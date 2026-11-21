<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * A Hardcover connection holds the reader's own API key. One made with a
 * code through an operator's app holds that app's tokens instead, which the
 * import does not read. Every Hardcover connection there is when this runs
 * was made so, since the code that makes the other kind runs only after: all
 * are deleted, and the card offers to connect as for an account never
 * connected.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('service_connections')->where('service', 'hardcover')->delete();
    }

    /**
     * Does not bring back the deleted connections; their users connect them
     * again. The schema itself is untouched.
     */
    public function down(): void {}
};
