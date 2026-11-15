<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Titles, people and companies the library has touched, and who made what.
 */
return new class extends Migration
{
    public function up(): void
    {
        // A row exists once someone interacts with the title (mark, rate, add
        // to a list); browsing and search run on the provider cache alone.
        // Columns hold what every media type shares; the rest lives in
        // `metadata`, read through ItemMetadata.
        Schema::create('media_items', function (Blueprint $table) {
            $table->id();
            $table->string('type'); // movie / show / season / episode / …
            $table->string('provider');
            $table->string('provider_ref');
            $table->foreignId('parent_id')->nullable()->constrained('media_items')->cascadeOnDelete();
            $table->unsignedInteger('number')->nullable(); // season or episode number within the parent
            $table->string('title');
            $table->unsignedSmallInteger('year')->nullable();
            $table->date('release_date')->nullable(); // premiere / air date
            $table->string('release_date_precision')->default('day');
            // The exact broadcast moment (UTC) of an episode whose show has an
            // airing slot; the frontend shows it in the viewer's time zone.
            // release_date stays the grouping key.
            $table->timestamp('release_time')->nullable();
            // The earlier of a film's digital and disc release in any region:
            // the date a calendar range can filter on, which a JSON key cannot.
            $table->date('home_release_date')->nullable();
            $table->unsignedInteger('runtime')->nullable(); // minutes
            // Watch time of the whole item (a season sums its episodes, a show
            // its seasons): a card value and a sort key, so it is stored
            // rather than walked per query. TitleStore writes it on every persist.
            $table->unsignedInteger('total_runtime')->nullable(); // minutes
            $table->text('overview')->nullable();
            $table->string('poster_path')->nullable();
            $table->json('localized_titles')->nullable();
            $table->string('backdrop_path')->nullable();
            $table->decimal('community_rating', 3, 1)->nullable(); // 0.0–10.0
            $table->json('metadata')->nullable();
            $table->string('status')->nullable();
            $table->timestamp('refreshed_at')->nullable();
            // Set on a root its source no longer has while someone keeps it in
            // their library: the title stays as an archive entry. Cleared when
            // the source answers for it again.
            $table->timestamp('gone_at')->nullable()->index();
            $table->timestamps();

            $table->unique(['type', 'provider_ref', 'provider']);
            $table->index(['parent_id', 'number']);
            $table->index('release_date');
            $table->index('home_release_date');
            $table->index('total_runtime');
        });

        Schema::create('people', function (Blueprint $table) {
            $table->id();
            $table->string('provider');
            $table->string('provider_ref');
            $table->string('name');
            $table->string('profile_path')->nullable();
            $table->text('biography')->nullable();
            $table->date('birthday')->nullable();
            $table->date('deathday')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamp('refreshed_at')->nullable();
            $table->timestamps();

            $table->unique(['provider', 'provider_ref']);
        });

        // A game studio or publisher, or a TV network, told apart by `kind`.
        // `kind` is part of the identity: a network id and a company id of one
        // provider may collide.
        Schema::create('companies', function (Blueprint $table) {
            $table->id();
            $table->string('provider');
            $table->string('kind')->default('company'); // company | network
            $table->string('provider_ref');
            $table->string('name');
            $table->string('logo_path')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamp('refreshed_at')->nullable();
            $table->timestamps();

            $table->unique(['provider', 'kind', 'provider_ref']);
        });

        Schema::create('credits', function (Blueprint $table) {
            $table->id();
            $table->foreignId('media_item_id')->constrained()->cascadeOnDelete();
            $table->foreignId('person_id')->constrained()->cascadeOnDelete();
            $table->string('department');
            $table->string('role')->nullable(); // character or job title
            $table->unsignedInteger('order')->default(0);
            // In how many episodes of a show someone appeared; null where the
            // provider has no such count ("unknown", not "zero episodes").
            $table->unsignedInteger('episode_count')->nullable();
            $table->timestamps();

            $table->index(['media_item_id', 'department']);
            $table->index('person_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('credits');
        Schema::dropIfExists('companies');
        Schema::dropIfExists('people');
        Schema::dropIfExists('media_items');
    }
};
