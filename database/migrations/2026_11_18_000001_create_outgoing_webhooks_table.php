<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Addresses of the user's own (n8n, Home Assistant, a script) that hear,
 * signed, about what happens in their library. The address and its secret
 * are credentials, so both are encrypted. Incoming webhooks keep their own
 * `webhook_*` tables.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('outgoing_webhooks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('label');
            $table->text('url');
            $table->text('secret');
            // The groups of events the receiver asked for.
            $table->json('events');
            $table->boolean('enabled')->default(true);
            // How the last delivery failed; cleared by the next one that arrives.
            $table->string('last_failure')->nullable();
            $table->timestamps();

            // Every change to a library asks for its owner's enabled webhooks.
            $table->index(['user_id', 'enabled']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('outgoing_webhooks');
    }
};
