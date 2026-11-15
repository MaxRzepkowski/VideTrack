<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Showcases and shows of a medium (Summer Game Fest, a Nintendo Direct).
 */
return new class extends Migration
{
    public function up(): void
    {
        // As their provider lists them: the calendar and the "live" button
        // read them locally, a daily task keeps them.
        Schema::create('catalog_events', function (Blueprint $table) {
            $table->id();
            $table->string('provider');
            $table->string('ref');
            $table->string('name');
            $table->text('description')->nullable();
            $table->timestamp('starts_at')->index();
            $table->timestamp('ends_at')->nullable();
            $table->string('time_zone')->nullable();
            $table->text('live_stream_url')->nullable();
            $table->string('logo_path')->nullable();
            $table->timestamps();

            $table->unique(['provider', 'ref']);
        });

        // The titles an event showed, each as a reference with the card it was
        // listed with (the catalog's snapshot shape).
        Schema::create('catalog_event_titles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('catalog_event_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('position');
            $table->string('type');
            $table->string('provider');
            $table->string('ref');
            $table->string('title');
            $table->date('release_date')->nullable();
            $table->unsignedInteger('votes')->nullable();
            $table->json('card');

            $table->unique(['catalog_event_id', 'type', 'provider', 'ref']);
            $table->index(['type', 'provider', 'ref']);
        });

        // An event a user took off their calendar and their "live" button.
        Schema::create('hidden_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('catalog_event_id')->constrained()->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['user_id', 'catalog_event_id']);
            $table->index('catalog_event_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('hidden_events');
        Schema::dropIfExists('catalog_event_titles');
        Schema::dropIfExists('catalog_events');
    }
};
