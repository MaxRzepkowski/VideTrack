<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A user's webhook and its digest slot are kept on their notification
 * channel, so the user columns go. down() brings the columns back empty;
 * rolling back the move of the webhooks to channels fills them again.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['discord_webhook_url', 'discord_digest_at']);
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->text('discord_webhook_url')->nullable();
            $table->timestamp('discord_digest_at')->nullable();
        });
    }
};
