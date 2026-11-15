<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What the user logged, rated and set, and the projection read from it.
 */
return new class extends Migration
{
    public function up(): void
    {
        // A cycle records the user's choice to start a title from the
        // beginning again. Cycle one is implicit: its plays have no cycle.
        Schema::create('watch_cycles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('media_item_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('number');
            $table->timestamp('started_at');
            $table->timestamps();

            $table->unique(['user_id', 'media_item_id', 'number']);
            $table->index(['user_id', 'media_item_id', 'id']);
            $table->index('media_item_id');
        });

        // The play log is the source of truth for "watched": a row names a
        // playable item, and one item may have many (a rewatch). The state of
        // a season or a show is derived, never stored here.
        Schema::create('plays', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('media_item_id')->constrained()->cascadeOnDelete();
            // A fallback only: CancelRewatch deletes the cycle's own plays
            // itself, and nothing else removes a cycle.
            $table->foreignId('watch_cycle_id')->nullable()->constrained('watch_cycles')->nullOnDelete();
            $table->timestamp('watched_at');
            $table->unsignedInteger('amount')->nullable();
            $table->string('outcome')->default('completed');
            $table->timestamps();

            $table->index(['user_id', 'media_item_id']);
            $table->index(['user_id', 'watched_at']);
            $table->index(['user_id', 'outcome', 'media_item_id'], 'plays_user_outcome_item_index');
            $table->index('watch_cycle_id');
            $table->index('media_item_id');
        });

        Schema::create('ratings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('media_item_id')->constrained()->cascadeOnDelete();
            $table->unsignedTinyInteger('rating'); // 1–10
            $table->timestamps();

            $table->unique(['user_id', 'media_item_id']);
            $table->index('media_item_id');
        });

        Schema::create('favorites', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('media_item_id')->constrained()->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['user_id', 'media_item_id']);
            $table->index('media_item_id');
        });

        // A root's viewing status (on hold) and the open progress of a
        // progress type; the history stays intact.
        Schema::create('media_user_states', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('media_item_id')->constrained()->cascadeOnDelete();
            $table->string('status');
            $table->unsignedInteger('progress')->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'media_item_id']);
            $table->index('media_item_id');
        });

        // The library's state per position, written only by LibraryProjector.
        Schema::create('library_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('media_item_id')->constrained()->cascadeOnDelete();
            $table->foreignId('root_id')->constrained('media_items')->cascadeOnDelete();
            $table->boolean('watched')->default(false);
            $table->dateTime('watched_at')->nullable();
            $table->dateTime('dropped_at')->nullable();
            $table->unsignedInteger('plays')->default(0);
            $table->unsignedInteger('cycle')->default(1);
            $table->unsignedInteger('completed_cycles')->default(0);
            $table->string('status')->nullable();
            $table->string('root_status')->nullable();
            $table->boolean('status_inherited')->default(false);
            $table->unsignedInteger('progress')->nullable();
            $table->unsignedInteger('played_amount')->nullable();
            $table->unsignedInteger('dropped_amount')->nullable();
            $table->boolean('latest_dropped')->default(false);
            $table->unsignedInteger('watched_episodes')->nullable();
            $table->unsignedInteger('aired_episodes')->nullable();
            $table->unsignedInteger('remaining_episodes')->nullable();
            $table->unsignedInteger('skipped_episodes')->nullable();
            $table->unsignedInteger('remaining_minutes')->nullable();
            $table->unsignedInteger('watched_minutes')->nullable();
            $table->unsignedInteger('position')->nullable();
            $table->foreignId('next_episode_id')->nullable()->constrained('media_items')->nullOnDelete();
            $table->boolean('in_progress')->default(false);
            $table->boolean('progression')->default(true);
            $table->json('segments')->nullable();
            // What the shelves read besides the state: whether anything at
            // all was logged at or below the position, and when a playable
            // under it last was.
            $table->boolean('played')->default(false);
            $table->dateTime('last_played_at')->nullable();

            $table->unique(['user_id', 'media_item_id']);
            $table->index(['user_id', 'root_id']);
            $table->index('media_item_id');
            $table->index('root_id');
            $table->index('next_episode_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('library_entries');
        Schema::dropIfExists('media_user_states');
        Schema::dropIfExists('favorites');
        Schema::dropIfExists('ratings');
        Schema::dropIfExists('plays');
        Schema::dropIfExists('watch_cycles');
    }
};
