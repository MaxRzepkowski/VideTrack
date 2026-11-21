<?php

use App\Models\ServiceConnection;
use App\Models\User;

/*
 * A Simkl connection made through the instance's app holds no app id of
 * its own; the migration lets it go, and nothing else.
 */

test('Simkl connections made through the instance\'s app are let go, and nothing else', function () {
    $migration = require database_path('migrations/2026_11_21_000005_forget_simkl_connections_made_through_the_instances_app.php');
    deviceConnection(User::factory()->create(), 'simkl');
    $other = deviceConnection(User::factory()->create(), 'hardcover');

    $migration->up();

    expect(ServiceConnection::query()->pluck('id')->all())->toBe([$other->id]);
});
