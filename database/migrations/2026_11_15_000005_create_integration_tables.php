<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Webhooks coming in, connected services and resolved outside ids.
 */
return new class extends Migration
{
    public function up(): void
    {
        // One address a media server posts playback to. The token in the URL
        // is the whole credential: kept encrypted so the settings page can
        // show the address, and found by its SHA-256.
        Schema::create('webhook_endpoints', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('source');
            // A user may keep several endpoints of one source ("Jellyfin
            // living room", "Jellyfin laptop").
            $table->string('label')->nullable();
            // Per-source options, validated by the source; nothing queries them.
            $table->json('settings')->nullable();
            $table->text('token');
            $table->string('token_hash', 64)->nullable()->unique();
            $table->boolean('enabled')->default(true);
            $table->timestamp('last_received_at')->nullable();
            $table->text('last_error')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'source']);
        });

        // Every delivery, accepted or not. A table rather than a JSON column:
        // the page pages through it, retention is one DELETE, and two
        // deliveries arriving at once cannot overwrite each other's log.
        Schema::create('webhook_deliveries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('webhook_endpoint_id')->constrained()->cascadeOnDelete();
            $table->string('status');
            $table->string('kind')->nullable();
            // "Dune (2021)": what the user reads in the log.
            $table->string('media_label')->nullable();
            // The translation key of why it was ignored, unmatched or failed.
            $table->string('message')->nullable();
            $table->json('payload')->nullable();
            $table->timestamps();

            $table->index(['webhook_endpoint_id', 'created_at']);
        });

        // Somebody else's id resolved to ours. Stored rather than cached: a
        // bulk import asks for the same ids thousands of times, and the
        // answers must survive a cache:clear.
        Schema::create('external_id_mappings', function (Blueprint $table) {
            $table->id();
            $table->string('source');
            $table->string('external_ref');
            // An IMDb id can be a film or a series, so the kind is part of
            // the identity.
            $table->string('media_kind');
            $table->string('type');
            $table->string('provider');
            $table->string('provider_ref');
            $table->timestamps();

            $table->unique(['source', 'external_ref', 'media_kind']);
        });

        // A user's link to a service the instance imports from. Data only
        // flows in: the row holds what it takes to read, never to write back.
        Schema::create('service_connections', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('service');
            // Tokens, keys, addresses, encrypted. Text, not a sized string:
            // token formats grow.
            $table->text('credentials');
            // What the user chose while connecting ("which Tracearr user am I").
            $table->json('settings')->nullable();
            // Where the last run stopped reading; the next one starts there.
            $table->json('state')->nullable();
            $table->string('status');
            // The account as the service names it, shown on the card.
            $table->string('account_label')->nullable();
            $table->boolean('auto_import')->default(false);
            $table->timestamp('last_imported_at')->nullable();
            $table->text('last_error')->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'service']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('service_connections');
        Schema::dropIfExists('external_id_mappings');
        Schema::dropIfExists('webhook_deliveries');
        Schema::dropIfExists('webhook_endpoints');
    }
};
