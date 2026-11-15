<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The catalog's own bookkeeping: refresh queue, change feeds, sets,
 * relations, franchises.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('title_refreshes', function (Blueprint $table) {
            $table->foreignId('media_item_id')->primary()->constrained('media_items')->cascadeOnDelete();
            $table->dateTime('asked_at')->nullable();
            $table->dateTime('due_at')->nullable()->index();
            $table->string('reason')->nullable();
        });

        Schema::create('change_cursors', function (Blueprint $table) {
            $table->id();
            $table->string('provider');
            $table->string('type');
            $table->dateTime('checked_until');

            $table->unique(['provider', 'type']);
        });

        Schema::create('catalog_sets', function (Blueprint $table) {
            $table->id();
            $table->string('kind');
            $table->string('provider');
            $table->string('ref');
            $table->string('locale');
            $table->string('name')->nullable();
            $table->string('poster_path')->nullable();
            $table->dateTime('wanted_at');
            $table->dateTime('fetched_at')->nullable()->index();

            $table->unique(['kind', 'provider', 'ref', 'locale']);
        });

        Schema::create('catalog_set_titles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('catalog_set_id')->constrained('catalog_sets')->cascadeOnDelete();
            $table->unsignedInteger('position');
            $table->string('type');
            $table->string('provider');
            $table->string('ref');
            $table->string('title');
            $table->date('release_date')->nullable();
            $table->unsignedInteger('votes')->nullable();
            // What the set's subject did on the title, where the set is a person's work.
            $table->string('department')->nullable();
            $table->string('role')->nullable();
            $table->unsignedInteger('billing')->nullable();
            $table->json('card');

            $table->unique(['catalog_set_id', 'position']);
            $table->index(['type', 'provider', 'ref']);
        });

        Schema::create('title_relations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('media_item_id')->constrained('media_items')->cascadeOnDelete();
            $table->string('relation');
            $table->string('type');
            $table->string('provider');
            $table->string('ref');
            $table->string('title');
            $table->date('release_date')->nullable();
            $table->json('card');

            $table->unique(['media_item_id', 'relation', 'type', 'ref']);
            $table->index(['type', 'provider', 'ref']);
        });

        Schema::create('title_relation_checks', function (Blueprint $table) {
            $table->foreignId('media_item_id')->primary()->constrained('media_items')->cascadeOnDelete();
            $table->dateTime('checked_at')->index();
        });

        Schema::create('franchises', function (Blueprint $table) {
            // The source's own id of the franchise.
            $table->string('source_id')->primary();
            $table->dateTime('checked_at')->nullable();
        });

        Schema::create('franchise_members', function (Blueprint $table) {
            $table->id();
            $table->string('franchise');
            $table->string('type');
            $table->string('provider');
            $table->string('ref');
            $table->string('title');
            $table->date('release_date')->nullable();
            $table->unsignedInteger('votes')->nullable();
            // The provider collection the title is a part of, where it has one.
            $table->string('collection_ref')->nullable();
            $table->json('card');

            $table->unique(['franchise', 'type', 'ref']);
            $table->index(['type', 'provider', 'ref']);
        });

        Schema::create('title_franchises', function (Blueprint $table) {
            $table->foreignId('media_item_id')->constrained('media_items')->cascadeOnDelete();
            $table->string('franchise')->index();

            $table->primary(['media_item_id', 'franchise']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('title_franchises');
        Schema::dropIfExists('franchise_members');
        Schema::dropIfExists('franchises');
        Schema::dropIfExists('title_relation_checks');
        Schema::dropIfExists('title_relations');
        Schema::dropIfExists('catalog_set_titles');
        Schema::dropIfExists('catalog_sets');
        Schema::dropIfExists('change_cursors');
        Schema::dropIfExists('title_refreshes');
    }
};
