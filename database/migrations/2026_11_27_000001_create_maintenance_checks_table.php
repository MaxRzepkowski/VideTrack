<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Where each maintenance check stands: queued, at work, or how it ended the
 * last time and when. One row per check, overwritten on every run, so a check
 * every quarter of an hour does not grow the task history.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('maintenance_checks', function (Blueprint $table) {
            $table->id();
            $table->string('code')->unique();
            $table->string('status');
            $table->uuid('run_id')->nullable();
            $table->json('result')->nullable();
            $table->json('error')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('checked_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('maintenance_checks');
    }
};
