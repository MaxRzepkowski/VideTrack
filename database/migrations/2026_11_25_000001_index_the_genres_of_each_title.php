<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * The genre list of each title beside its id, so the genre filter of a
 * user's panels reads a library's genres from the index alone: reading them
 * from the rows would parse the whole metadata of every title in it. The
 * expression is the one the query builder writes for the genres column
 * (`metadata->genres`), character for character, or the index goes unused.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement("create index media_items_id_genres_index on media_items (id, json_extract(\"metadata\", '$.\"genres\"'))");
    }

    public function down(): void
    {
        DB::statement('drop index media_items_id_genres_index');
    }
};
