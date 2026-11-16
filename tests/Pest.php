<?php

use App\Integrations\Connections\HostResolver;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\Fakes\FakeHostResolver;
use Tests\TestCase;

// Every Feature test: a fresh database, no real service, one frozen instant,
// and the library projection checked against the history it came from.
pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    // No test reaches a real service: a URL without a fake fails the test
    // instead of going out. Laravel sends unmatched requests for real, so a
    // forgotten fake would hit a provider with a dummy key on every run: the
    // burst of 401s that gets an address banned.
    ->beforeEach(function () {
        Http::preventStrayRequests();
        resetFakes();
        // Nor DNS: a connect form's address resolves from a fixed table.
        app()->instance(HostResolver::class, new FakeHostResolver);
        // Nor the front-end build: a page renders without a running Vite or
        // a built manifest, as on a fresh clone or in CI.
        $this->withoutVite();
        // Every test runs at the same instant: a fixture date that is
        // "upcoming" stays upcoming, and nothing flips at midnight. A test
        // that needs another moment travels from here.
        $this->travelTo(CarbonImmutable::parse('2026-10-10 12:00:00'));
    })
    ->afterEach(function () {
        removeScratch();
        assertProjectionMatchesHistory();
    })
    ->in('Feature');

pest()->afterEach(fn () => removeScratch())->in('Unit');

/*
|--------------------------------------------------------------------------
| Shared helpers
|--------------------------------------------------------------------------
|
| Helpers more than one test file needs live in tests/Support, one file per
| topic. A helper only one file needs stays in that file.
|
*/

require_once __DIR__.'/Support/accounts.php';
require_once __DIR__.'/Support/anime.php';
require_once __DIR__.'/Support/fakes.php';
require_once __DIR__.'/Support/files.php';
require_once __DIR__.'/Support/fixtures.php';
require_once __DIR__.'/Support/golden.php';
require_once __DIR__.'/Support/modules.php';
require_once __DIR__.'/Support/library.php';
require_once __DIR__.'/Support/notifications.php';
require_once __DIR__.'/Support/pages.php';
require_once __DIR__.'/Support/integrations.php';
require_once __DIR__.'/Support/providers.php';
require_once __DIR__.'/Support/tasks.php';
require_once __DIR__.'/Support/suggestions.php';
