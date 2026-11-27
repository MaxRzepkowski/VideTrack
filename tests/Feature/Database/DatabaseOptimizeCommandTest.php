<?php

use App\Models\Play;
use Illuminate\Support\Facades\DB;

test('the planner gets statistics for every table with rows', function () {
    Play::factory()->count(3)->create();
    projectEveryLibrary();

    $this->artisan('vide:database:optimize')
        ->expectsOutput('The query planner statistics are up to date.')
        ->assertSuccessful();

    expect(DB::table('sqlite_stat1')->distinct()->pluck('tbl')->all())
        ->toContain('plays', 'media_items', 'library_entries');
});
