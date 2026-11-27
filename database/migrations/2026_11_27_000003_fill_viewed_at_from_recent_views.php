<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * A title's last opening, as far as the users' "recently viewed" still knows
 * it. Earlier openings that history already let go of stay unknown. A view
 * names its title by type and ref, not provider, so a ref two providers share
 * marks both opened: the safe side, which keeps a title rather than losing it.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('
            update media_items set viewed_at = (
                select max(recent_views.viewed_at) from recent_views
                where recent_views.type = media_items.type and recent_views.provider_ref = media_items.provider_ref
            )
            where parent_id is null and exists (
                select 1 from recent_views
                where recent_views.type = media_items.type and recent_views.provider_ref = media_items.provider_ref
            )
        ');
    }

    /** Clears every title's last opening, those recorded after this migration too; the column goes with the schema migration before it. */
    public function down(): void
    {
        DB::table('media_items')->update(['viewed_at' => null]);
    }
};
