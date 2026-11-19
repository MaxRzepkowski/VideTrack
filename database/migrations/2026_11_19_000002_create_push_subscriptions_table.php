<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The browsers a web push channel delivers to, one row each. The address and
 * the keys a message is encrypted with are credentials, so they are
 * encrypted; the address is found again by its hash.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('push_subscriptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('notification_channel_id')->constrained()->cascadeOnDelete();
            // sha256 of the endpoint: one browser is one row, whoever subscribed it last.
            $table->string('endpoint_hash', 64)->unique();
            $table->text('endpoint');
            $table->text('public_key');
            $table->text('auth_token');
            $table->string('content_encoding');
            // The instance's public key the browser subscribed with: a new pair leaves this row unusable.
            $table->string('vapid_key');
            $table->string('user_agent')->nullable();
            $table->timestamps();

            $table->index('notification_channel_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('push_subscriptions');
    }
};
