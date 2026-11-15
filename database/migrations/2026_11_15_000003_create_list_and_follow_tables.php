<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A user's lists and the ownerless lists of provider collections. Bells:
 * what a user follows or mutes, and who sponsors a title's bell.
 */
return new class extends Migration
{
    public function up(): void
    {
        // The watchlist is a system list (system_key = 'watchlist'); custom
        // lists have none. A collection is an ownerless system list keyed by
        // its provider collection. A system list has no title: the UI names it.
        Schema::create('lists', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('title')->nullable();
            $table->text('description')->nullable();
            $table->boolean('is_public')->default(true);
            $table->string('system_key')->nullable();
            $table->string('backdrop_path')->nullable();
            $table->json('images')->nullable();
            // What a collection is tied to, as its provider names it (a game
            // series' parent series, subseries, spin-offs).
            $table->text('collection_relations')->nullable();
            $table->unsignedInteger('position')->default(0);
            $table->timestamps();

            $table->unique(['user_id', 'system_key']);
        });
        // A unique index treats NULLs as distinct, so ownerless collections
        // need their own.
        DB::statement('CREATE UNIQUE INDEX lists_ownerless_system_key_unique ON lists (system_key) WHERE user_id IS NULL');

        // A position targets exactly one of a title, a person or a company.
        // People and companies stay off the watchlist: it means "intend to
        // consume", which neither can be.
        Schema::create('list_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('list_id')->constrained('lists')->cascadeOnDelete();
            $table->foreignId('media_item_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('person_id')->nullable()->constrained('people')->cascadeOnDelete();
            $table->foreignId('company_id')->nullable()->constrained('companies')->cascadeOnDelete();
            $table->unsignedInteger('position');
            $table->timestamps();

            $table->unique(['list_id', 'media_item_id']);
            $table->unique(['list_id', 'person_id']);
            $table->unique(['list_id', 'company_id']);
            $table->index(['list_id', 'position']);
            $table->index('media_item_id');
            $table->index('person_id');
            $table->index('company_id');
        });
        $this->requireOneTarget('list_items', ['media_item_id', 'person_id', 'company_id']);

        // Exceptions to the tracking matrix, one target per row: a root title,
        // a person, a company or a provider collection (an ownerless list).
        // Separate nullable columns rather than a polymorphic pair, because
        // people, companies and collections sit outside the media types.
        Schema::create('follows', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('media_item_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('person_id')->nullable()->constrained('people')->cascadeOnDelete();
            $table->foreignId('company_id')->nullable()->constrained('companies')->cascadeOnDelete();
            // The cascade matters: a collection the provider dropped is
            // deleted, and its bell goes with it.
            $table->foreignId('list_id')->nullable()->constrained('lists')->cascadeOnDelete();
            $table->string('mode'); // follow | mute
            $table->timestamps();

            $table->unique(['user_id', 'media_item_id']);
            $table->unique(['user_id', 'person_id']);
            $table->unique(['user_id', 'company_id']);
            $table->unique(['user_id', 'list_id']);
            $table->index('media_item_id');
            $table->index('person_id');
            $table->index('company_id');
            $table->index('list_id');
        });
        $this->requireOneTarget('follows', ['media_item_id', 'person_id', 'company_id', 'list_id']);

        // Who backs an automatic bell on a title: two followed actors of one
        // film both sponsor it, and unfollowing one removes only their
        // sponsorship. The title keeps its bell until nobody backs it.
        Schema::create('follow_person', function (Blueprint $table) {
            $table->foreignId('follow_id')->constrained()->cascadeOnDelete();
            $table->foreignId('person_id')->constrained('people')->cascadeOnDelete();

            $table->unique(['follow_id', 'person_id']);
            $table->index('person_id');
        });

        Schema::create('follow_company', function (Blueprint $table) {
            $table->foreignId('follow_id')->constrained()->cascadeOnDelete();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();

            $table->unique(['follow_id', 'company_id']);
            $table->index('company_id');
        });

        Schema::create('follow_list', function (Blueprint $table) {
            $table->foreignId('follow_id')->constrained()->cascadeOnDelete();
            $table->foreignId('list_id')->constrained('lists')->cascadeOnDelete();

            $table->unique(['follow_id', 'list_id']);
            $table->index('list_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('follow_list');
        Schema::dropIfExists('follow_company');
        Schema::dropIfExists('follow_person');
        Schema::dropIfExists('follows');
        Schema::dropIfExists('list_items');
        Schema::dropIfExists('lists');
    }

    /**
     * Exactly one of the target columns is set. SQLite takes a CHECK only in
     * CREATE TABLE and the schema builder writes none, so the table, still
     * empty, is created again with it, and its indexes after it.
     *
     * @param  list<string>  $columns
     */
    private function requireOneTarget(string $table, array $columns): void
    {
        $create = DB::scalar("select sql from sqlite_master where type = 'table' and name = ?", [$table]);

        if (! is_string($create)) {
            throw new RuntimeException("Table {$table} has no definition to add a check to.");
        }

        $indexes = DB::table('sqlite_master')
            ->where('type', 'index')
            ->where('tbl_name', $table)
            ->whereNotNull('sql')
            ->pluck('sql');

        $check = implode(' + ', array_map(fn (string $column): string => "(\"{$column}\" is not null)", $columns));

        DB::statement("drop table \"{$table}\"");
        DB::statement(substr($create, 0, -1).", constraint \"{$table}_one_target\" check ({$check} = 1))");

        foreach ($indexes as $index) {
            if (is_string($index)) {
                DB::statement($index);
            }
        }
    }
};
