<?php

use App\Library\MutationSource;

test('plain kinds carry no code', function () {
    expect(MutationSource::ui()->value())->toBe('ui')
        ->and(MutationSource::ui()->code)->toBeNull()
        ->and(MutationSource::api()->value())->toBe('api');
});

test('parameterized kinds carry their source code', function () {
    expect(MutationSource::import('trakt')->value())->toBe('import:trakt')
        ->and(MutationSource::scrobble('jellyfin')->value())->toBe('scrobble:jellyfin')
        ->and(MutationSource::import('trakt')->code)->toBe('trakt');
});

test('value survives a round trip through fromValue', function (string $value) {
    expect(MutationSource::fromValue($value)->value())->toBe($value);
})->with(['ui', 'api', 'import:trakt', 'scrobble:jellyfin']);

test('rejects values that are not sources', function (string $value) {
    expect(fn () => MutationSource::fromValue($value))->toThrow(InvalidArgumentException::class);
})->with(['sync:trakt', 'import', 'ui:trakt', 'api:mcp', '', 'IMPORT:trakt']);

test('rejects codes outside the lowercase slug format', function (string $code) {
    expect(fn () => MutationSource::import($code))->toThrow(InvalidArgumentException::class);
})->with(['', 'Trakt', 'tra kt', 'trakt:v2', '-trakt']);

test('is() matches the kind and optionally the code', function () {
    $source = MutationSource::scrobble('jellyfin');

    expect($source->is('scrobble'))->toBeTrue()
        ->and($source->is('scrobble', 'jellyfin'))->toBeTrue()
        ->and($source->is('scrobble', 'plex'))->toBeFalse()
        ->and($source->is('import'))->toBeFalse()
        ->and(MutationSource::ui()->is('ui', 'trakt'))->toBeFalse();
});

test('a stored value this version cannot read is no source, rather than an error', function () {
    expect(MutationSource::tryFromValue('scrobble:jellyfin')?->value())->toBe('scrobble:jellyfin')
        ->and(MutationSource::tryFromValue(null))->toBeNull()
        ->and(MutationSource::tryFromValue('carrier-pigeon'))->toBeNull()
        ->and(MutationSource::tryFromValue('import:Not Valid'))->toBeNull();
});
