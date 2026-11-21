<?php

use App\Integrations\Connections\ConnectionStatus;
use App\Models\User;
use App\Support\StoredReason;

/*
 * A Hardcover connection made with a code through an operator's app holds
 * tokens the import does not read; the migration expires it, and only it.
 */

test('an active Hardcover connection is expired with a reason, and back again', function () {
    $migration = require database_path('migrations/2026_11_21_000003_expire_hardcover_connections_made_by_a_code.php');
    $hardcover = deviceConnection(User::factory()->create(), 'hardcover');
    $other = deviceConnection(User::factory()->create(), 'simkl');

    $migration->up();

    expect($hardcover->refresh()->status)->toBe(ConnectionStatus::Expired)
        ->and(StoredReason::worded($hardcover->last_error))->toBe('Hardcover stopped accepting this API key. Connect again with a current one.')
        ->and($other->refresh()->status)->toBe(ConnectionStatus::Active);

    $migration->down();

    expect($hardcover->refresh()->status)->toBe(ConnectionStatus::Active)
        ->and($hardcover->last_error)->toBeNull();
});
