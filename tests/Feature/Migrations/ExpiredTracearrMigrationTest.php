<?php

use App\Integrations\Connections\ConnectionStatus;
use App\Models\User;
use App\Support\StoredReason;

/*
 * A Tracearr connection that expired under the old wording keeps a key no
 * language has; the migration rewords it, and only it.
 */

test('an expired Tracearr connection is reworded, and back again', function () {
    $migration = require database_path('migrations/2026_11_21_000001_reword_an_expired_tracearr_connection.php');
    $tracearr = activeConnection(User::factory()->create(), 'tracearr', ['url' => 'http://tracearr.lan:3000', 'key' => 'trr_pub_secret'], [
        'status' => ConnectionStatus::Expired,
        'last_error' => 'import.tracearr_stopped_accepting_this_api_key',
    ]);
    $other = activeConnection(User::factory()->create(), 'simkl', ['access_token' => 'token-of-alex'], [
        'status' => ConnectionStatus::Expired,
        'last_error' => 'import.simkl_stopped_accepting_this_connection_connect',
    ]);

    $migration->up();

    expect(StoredReason::worded($tracearr->refresh()->last_error))->toBe('The server stopped accepting this API key. Connect it again with a current key.')
        ->and($other->refresh()->last_error)->toBe('import.simkl_stopped_accepting_this_connection_connect');

    $migration->down();

    expect($tracearr->refresh()->last_error)->toBe('import.tracearr_stopped_accepting_this_api_key');
});
