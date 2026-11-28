<?php

use App\Integrations\Connections\ConnectionRegistry;
use App\Integrations\Connections\ConnectionStatus;
use App\Integrations\Import\ImportInputKind;
use App\Integrations\Import\ImportSourceRegistry;
use App\Integrations\Jellyfin\JellyfinScrobbleSource;
use App\Integrations\Plex\PlexScrobbleSource;
use App\Models\ServiceConnection;
use App\Models\User;
use Inertia\Testing\AssertableInertia;
use Tests\Fakes\FakeConnectionImportSource;
use Tests\Fakes\FakeDeviceService;

beforeEach(function () {
    app(ConnectionRegistry::class)->register(FakeDeviceService::CODE, FakeDeviceService::class);
    app(ImportSourceRegistry::class)->register(FakeConnectionImportSource::CODE, FakeConnectionImportSource::class);
});

/**
 * The page's two sections as "kind:code".
 *
 * @return array{connected: list<string>, available: list<string>}
 */
function integrationSectionsOf(AssertableInertia $page): array
{
    $props = $page->toArray()['props']['page'];
    $keys = fn (array $refs): array => array_map(fn (array $ref): string => "{$ref['kind']}:{$ref['code']}", $refs);

    return ['connected' => $keys($props['connected']), 'available' => $keys($props['available'])];
}

test('what is set up leads in the order it was set up, servers and accounts alike', function () {
    $user = User::factory()->create();

    scrobbleEndpoint($user, PlexScrobbleSource::CODE, ['created_at' => now()->subDays(3)]);
    ServiceConnection::factory()->for($user)->create([
        'service' => FakeDeviceService::CODE,
        // An account that stopped working still belongs up there, asking to connect again.
        'status' => ConnectionStatus::Expired,
        'created_at' => now()->subDays(2),
    ]);
    scrobbleEndpoint($user, JellyfinScrobbleSource::CODE, ['created_at' => now()->subDay()]);

    $this->actingAs($user)
        ->get(route('settings.integrations.index'))
        ->assertInertia(function (AssertableInertia $page): void {
            $sections = integrationSectionsOf($page);

            expect($sections['connected'])->toBe(['webhook:plex', 'account:'.FakeConnectionImportSource::CODE, 'webhook:jellyfin'])
                ->and($sections['available'])->not->toContain('webhook:plex', 'webhook:jellyfin', 'account:'.FakeConnectionImportSource::CODE);
        });
});

test('what can be added is one list by name, servers and accounts mixed', function () {
    $this->actingAs(User::factory()->create())
        ->get(route('settings.integrations.index'))
        ->assertInertia(function (AssertableInertia $page): void {
            $props = $page->toArray()['props']['page'];
            $names = collect($props['webhooks'])->pluck('name', 'code')->mapWithKeys(fn ($name, $code) => ["webhook:{$code}" => $name])
                ->merge(collect($props['accounts'])->pluck('label', 'code')->mapWithKeys(fn ($label, $code) => ["account:{$code}" => $label]));
            $available = integrationSectionsOf($page)['available'];
            $byName = $names->only($available)->sort(fn (string $a, string $b): int => strnatcasecmp($a, $b))->keys()->all();

            expect($props['connected'])->toBe([])
                ->and($available)->toHaveCount($names->count())
                ->and($available)->toBe($byName);
        });
});

test('the import page leaves account sources to Integrations', function () {
    $this->actingAs(User::factory()->create())
        ->get(route('settings.import.index'))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('settings/Import')
            ->where('sources', fn ($sources) => collect($sources)->every(fn ($source) => $source['inputKind'] !== ImportInputKind::Connection->value)
                && ! collect($sources)->contains('code', FakeConnectionImportSource::CODE)));
});

test('a link to an account source on the import page lands on its card in Integrations', function () {
    $this->actingAs(User::factory()->create())
        ->get(route('settings.import.index', ['source' => FakeConnectionImportSource::CODE]))
        ->assertRedirect(route('settings.integrations.index', ['source' => FakeConnectionImportSource::CODE]));

    $this->get(route('settings.integrations.index', ['source' => FakeConnectionImportSource::CODE]))
        ->assertInertia(fn (AssertableInertia $page) => $page->where('page.selected', FakeConnectionImportSource::CODE));
});

test('only an account source is opened on arrival', function () {
    $this->actingAs(User::factory()->create())
        ->get(route('settings.integrations.index', ['source' => JellyfinScrobbleSource::CODE]))
        ->assertInertia(fn (AssertableInertia $page) => $page->where('page.selected', null));

    $this->get(route('settings.integrations.index', ['source' => 'nothing-like-it']))
        ->assertInertia(fn (AssertableInertia $page) => $page->where('page.selected', null));
});
