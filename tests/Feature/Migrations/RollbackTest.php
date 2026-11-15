<?php

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schema;

/*
 * A migration's down() runs only on the day somebody needs it, so it is run
 * here: on a scratch database the schema is rolled back to nothing, then
 * migrated forward again.
 */
test('the schema rolls back to nothing and migrates forward again', function () {
    $file = scratchPath('rollback', '.sqlite');
    config(['database.connections.scratch' => [...config()->array('database.connections.sqlite'), 'database' => $file]]);

    expect(Artisan::call('migrate', ['--database' => 'scratch', '--force' => true]))->toBe(0)
        ->and(Artisan::call('migrate:rollback', ['--database' => 'scratch', '--force' => true]))->toBe(0)
        ->and(Schema::connection('scratch')->getTableListing(schemaQualified: false))->toBe(['migrations'])
        ->and(Artisan::call('migrate', ['--database' => 'scratch', '--force' => true]))->toBe(0)
        ->and(Schema::connection('scratch')->hasTable('users'))->toBeTrue();
});
