<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Where each history entry came in from (`ui`, `api`, `import:trakt`,
 * `scrobble:jellyfin`…). Entries written before this column stay null: their
 * origin was never kept and is not guessed.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('plays', function (Blueprint $table) {
            $table->string('source')->nullable()->after('outcome');
        });
    }

    public function down(): void
    {
        Schema::table('plays', function (Blueprint $table) {
            $table->dropColumn('source');
        });
    }
};
