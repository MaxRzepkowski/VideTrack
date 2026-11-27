<?php

use App\Jobs\CleanupExpiredData;
use Illuminate\Support\Facades\DB;

test('expired rows of the database cache go, whichever store the cache uses now', function (string $store) {
    config(['cache.default' => $store]);
    $table = config()->string('cache.stores.database.table');
    DB::table($table)->insert([
        ['key' => 'expired-of-alex', 'value' => 'x', 'expiration' => now()->subMinute()->getTimestamp()],
        ['key' => 'fresh-of-alex', 'value' => 'x', 'expiration' => now()->addMinute()->getTimestamp()],
    ]);

    CleanupExpiredData::queueTask();

    $left = DB::table($table)->pluck('key')->all();

    expect($left)->toContain('fresh-of-alex')
        ->and($left)->not->toContain('expired-of-alex');
})->with(['the database' => ['database'], 'files, after it moved off the database' => ['file']]);
