<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Where a user's notifications go beyond the bell: each row is one channel
 * (a webhook, the addresses of a delivery service…) with its own delivery mode.
 * Its settings are credentials, so the whole column is encrypted.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notification_channels', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('driver');
            // Which of the driver's kinds: a driver may deliver to many services.
            $table->string('kind');
            $table->string('label');
            $table->text('config');
            $table->string('mode');
            $table->boolean('enabled')->default(true);
            // How the last delivery failed; cleared by the next one that arrives.
            $table->string('last_failure')->nullable();
            $table->timestamp('digest_sent_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'mode']);
            // The daily digest walks every user's enabled digest channels.
            $table->index(['mode', 'enabled']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notification_channels');
    }
};
