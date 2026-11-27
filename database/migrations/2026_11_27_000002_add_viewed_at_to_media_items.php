<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * When anyone last opened a title's page, kept on the title: a user's own
 * "recently viewed" keeps only their newest few, so it cannot say whether a
 * title was opened in the last months. The orphan sweep reads it, beside the
 * day the title was saved, from the roots.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('media_items', function (Blueprint $table) {
            $table->timestamp('viewed_at')->nullable();
            $table->index(['parent_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::table('media_items', function (Blueprint $table) {
            $table->dropIndex(['parent_id', 'created_at']);
            $table->dropColumn('viewed_at');
        });
    }
};
