<?php

use App\Integrations\Connections\ConnectionStatus;
use App\Models\User;
use App\Support\StoredReason;

/*
 * A MangaBaka connection made through an operator's OAuth app holds tokens
 * the import does not read; the migration expires it, and only it.
 */

test('an active MangaBaka connection is expired with a reason, and back again', function () {
    $migration = require database_path('migrations/2026_11_21_000002_expire_mangabaka_connections_made_by_oauth.php');
    $mangabaka = deviceConnection(User::factory()->create(), 'mangabaka');
    $other = deviceConnection(User::factory()->create(), 'simkl');

    $migration->up();

    expect($mangabaka->refresh()->status)->toBe(ConnectionStatus::Expired)
        ->and(StoredReason::worded($mangabaka->last_error))->toBe('MangaBaka stopped accepting this token. Connect again with a current one.')
        ->and($other->refresh()->status)->toBe(ConnectionStatus::Active);

    $migration->down();

    expect($mangabaka->refresh()->status)->toBe(ConnectionStatus::Active)
        ->and($mangabaka->last_error)->toBeNull();
});
