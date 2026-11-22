<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * How many rated the tied title at its provider when the tie was found, so
 * that a page can leave out what is too little known to put beside it.
 * Empty where the title is stored here.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('title_relations', function (Blueprint $table) {
            $table->unsignedInteger('votes')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('title_relations', function (Blueprint $table) {
            $table->dropColumn('votes');
        });
    }
};
