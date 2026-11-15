<?php

use App\Catalog\ItemNotFoundException;
use App\Catalog\ProviderException;
use App\Catalog\Providers\Tmdb\TmdbClient;
use App\Catalog\Providers\Tmdb\TmdbProvider;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

// A dropped connection must not carry the TMDB v3 key (sent in the query
// string) into the exception that reaches logs, task runs and failed_jobs.

test('an unreachable provider is reported without the key from the request URI', function () {
    config(['tracker.tmdb.api_key' => 'v3-secret-key']);

    Http::fake(fn () => throw new ConnectionException(
        'cURL error 28: Operation timed out after 15002 milliseconds with 0 bytes received for https://api.themoviedb.org/3/movie/550?api_key=v3-secret-key',
    ));

    try {
        app(TmdbClient::class)->get('movie/550', [], 60);
        $this->fail('Expected a ProviderException.');
    } catch (ProviderException $e) {
        expect($e->getMessage())->toBe('TMDB is unreachable: Operation timed out after 15002 milliseconds with 0 bytes received')
            ->and($e->getPrevious())->toBeNull()
            ->and((string) $e)->not->toContain('v3-secret-key');
    }
});

test('a TMDB ref that steps out of its path segment never reaches TMDB', function (string $type, string $ref) {
    Http::fake();

    expect(fn () => app(TmdbProvider::class)->details($type, $ref, 'en'))
        ->toThrow(ItemNotFoundException::class);

    Http::assertNothingSent();
})->with([
    'movie climbing up' => ['movie', '603/../../account'],
    'show with a query' => ['show', '1399?api_key=x'],
    'season with a word' => ['season', '1399:abc'],
    'episode with a slash' => ['episode', '1399:1:1/../2'],
]);
