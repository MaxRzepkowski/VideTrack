<?php

use App\Integrations\Apprise\Data\AppriseService;
use App\Integrations\Apprise\Data\AppriseTemplate;

function ntfyService(): AppriseService
{
    return new AppriseService(
        code: 'ntfys',
        name: 'ntfy',
        setupUrl: null,
        plainScheme: 'ntfy',
        templates: array_map(AppriseTemplate::of(...), ['{schema}://{topic}', '{schema}://{host}/{targets}', '{schema}://{user}:{password}@{host}/{targets}']),
        tokens: [],
    );
}

test('a template names its tokens, the scheme left out', function () {
    expect(AppriseTemplate::of('{schema}://{user}:{password}@{host}:{port}/{targets}')->tokens)->toBe(['user', 'password', 'host', 'port', 'targets']);
});

test('a template is filled with each token in its place', function () {
    expect(AppriseTemplate::of('{schema}://{bot_token}/{targets}')->fill('tgram', ['bot_token' => '1%3Aabc', 'targets' => '-100']))->toBe('tgram://1%3Aabc/-100');
});

test('the template used is the one that takes the most of what was given, all of its own given', function () {
    $service = ntfyService();

    expect($service->templateFor(['topic'])?->pattern)->toBe('{schema}://{topic}')
        ->and($service->templateFor(['host', 'targets'])?->pattern)->toBe('{schema}://{host}/{targets}')
        ->and($service->templateFor(['host', 'targets', 'user', 'password'])?->pattern)->toBe('{schema}://{user}:{password}@{host}/{targets}')
        ->and($service->templateFor(['user']))->toBeNull();
});

test('a token every template names is one the service cannot go without', function () {
    $telegram = new AppriseService('tgram', 'Telegram', null, null, array_map(AppriseTemplate::of(...), ['{schema}://{bot_token}', '{schema}://{bot_token}/{targets}']), []);

    expect($telegram->needs('bot_token'))->toBeTrue()
        ->and($telegram->needs('targets'))->toBeFalse()
        ->and(ntfyService()->needs('topic'))->toBeFalse();
});
