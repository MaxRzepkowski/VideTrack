<?php

use App\Models\ServiceConnection;
use App\Models\User;

/*
 * A connection made through an operator's app holds tokens its import does
 * not read; each migration deletes those of its own service, and nothing
 * else, so the card reads as never connected.
 */

test('connections made through an operator\'s app are deleted, those of other services kept', function (string $migration, string $service) {
    $forget = require database_path("migrations/{$migration}.php");
    deviceConnection(User::factory()->create(), $service);
    $other = activeConnection(User::factory()->create(), 'tracearr', ['url' => 'http://tracearr.lan:3000', 'key' => 'trr_pub_secret']);

    $forget->up();

    expect(ServiceConnection::query()->pluck('id')->all())->toBe([$other->id]);
})->with([
    'MangaBaka' => ['2026_11_21_000002_forget_mangabaka_connections_made_through_an_operators_app', 'mangabaka'],
    'Hardcover' => ['2026_11_21_000003_forget_hardcover_connections_made_through_an_operators_app', 'hardcover'],
    'MDBList' => ['2026_11_21_000004_forget_mdblist_connections_made_through_an_operators_app', 'mdblist'],
    'Simkl' => ['2026_11_21_000005_forget_simkl_connections_made_through_an_operators_app', 'simkl'],
]);
