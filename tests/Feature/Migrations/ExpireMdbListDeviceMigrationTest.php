<?php

use App\Integrations\Connections\ConnectionStatus;
use App\Models\User;
use App\Support\StoredReason;

/*
 * An MDBList connection made with a code through an operator's app holds
 * tokens the import does not read; the migration expires it, and only it.
 */

test('an active MDBList connection is expired with a reason, and back again', function () {
    $migration = require database_path('migrations/2026_11_21_000004_expire_mdblist_connections_made_by_a_code.php');
    $mdblist = deviceConnection(User::factory()->create(), 'mdblist');
    $other = deviceConnection(User::factory()->create(), 'simkl');

    $migration->up();

    expect($mdblist->refresh()->status)->toBe(ConnectionStatus::Expired)
        ->and(StoredReason::worded($mdblist->last_error))->toBe('MDBList stopped accepting this API key. Connect again with a current one.')
        ->and($other->refresh()->status)->toBe(ConnectionStatus::Active);

    $migration->down();

    expect($mdblist->refresh()->status)->toBe(ConnectionStatus::Active)
        ->and($mdblist->last_error)->toBeNull();
});
