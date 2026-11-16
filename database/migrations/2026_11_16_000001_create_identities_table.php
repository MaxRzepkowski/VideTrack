<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The accounts at identity providers people sign in with. No provider token
 * is kept: once someone is signed in, the instance never asks the provider
 * anything again.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('identities', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('provider');
            // The person's id at the provider, which never changes; the
            // address beside it is only what the settings page shows.
            $table->string('subject');
            $table->string('email')->nullable();
            $table->timestamps();

            $table->unique(['provider', 'subject']);
            $table->unique(['user_id', 'provider']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('identities');
    }
};
