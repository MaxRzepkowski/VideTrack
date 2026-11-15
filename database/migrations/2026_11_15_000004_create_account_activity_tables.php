<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Notifications, recently viewed titles and background task runs.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notifications', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('type');
            $table->string('dedup_key')->nullable();
            $table->morphs('notifiable');
            $table->text('data');
            $table->timestamp('read_at')->nullable();
            $table->timestamps();

            $table->unique(['notifiable_type', 'notifiable_id', 'dedup_key']);
        });

        // A snapshot of each title page a user opened (never a season or an
        // episode), so the strip renders without a media item or a provider.
        Schema::create('recent_views', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('type');
            $table->string('provider_ref');
            $table->string('title');
            $table->string('poster_path')->nullable();
            $table->timestamp('viewed_at');

            $table->unique(['user_id', 'type', 'provider_ref']);
            $table->index(['user_id', 'viewed_at']);
        });

        // Background task results. `user_id` null marks an instance-wide task;
        // a user's own tasks (imports) belong to them.
        Schema::create('task_runs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('status'); // pending / started / success / failure
            $table->text('summary')->nullable();
            $table->json('errors')->nullable();
            $table->json('details')->nullable();
            $table->timestamp('queued_at');
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('task_runs');
        Schema::dropIfExists('recent_views');
        Schema::dropIfExists('notifications');
    }
};
