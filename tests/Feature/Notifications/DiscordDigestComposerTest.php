<?php

use App\Notifications\DiscordDigestComposer;
use Illuminate\Notifications\DatabaseNotification;

/*
 * The digest's text, apart from who gets it and when: one section per kind
 * of title in the registry's order, named in the reader's language, a series'
 * parts under the series, and Discord's length limit respected.
 */

/**
 * @param  array<string, mixed>  $media
 */
function digestNotification(array $media, string $event = 'premiere'): DatabaseNotification
{
    return (new DatabaseNotification)->forceFill(['data' => [
        'key' => 'k',
        'event' => $event,
        'media' => ['ref' => '1', 'title' => 'Title', 'number' => null, 'extra' => [], ...$media],
        'showTitle' => $media['showTitle'] ?? null,
        'date' => today()->toDateString(),
        'oldDate' => null,
        'status' => null,
        'oldStatus' => null,
        'season' => $media['season'] ?? null,
    ]]);
}

/**
 * @param  list<DatabaseNotification>  $notifications
 * @return list<string>
 */
function digestOf(array $notifications, string $locale = 'en'): array
{
    return app(DiscordDigestComposer::class)->messages(collect($notifications), $locale, 'UTC');
}

test('sections follow the registry, a series first, its episodes under it', function () {
    [$message] = digestOf([
        digestNotification(['type' => 'movie', 'title' => 'Dune']),
        digestNotification(['type' => 'episode', 'ref' => '1399:1:2', 'title' => 'Pilot', 'number' => 2, 'showTitle' => 'Severance', 'season' => 1], 'episode_aired'),
    ]);

    expect(strpos($message, '📺 **Shows**'))->toBeLessThan(strpos($message, '🎬 **Movies**'))
        ->and(strpos($message, '📺 **Shows**'))->toBeLessThan(strpos($message, 'Severance'))
        ->and(strpos($message, '🎬 **Movies**'))->toBeLessThan(strpos($message, '[Dune]'))
        ->and($message)->toStartWith('📬 **Your daily digest**')
        ->and($message)->toEndWith('Enjoy your media!');
});

test('headings speak the reader\'s language', function () {
    [$message] = digestOf([digestNotification(['type' => 'movie', 'title' => 'Diuna'])], 'pl');

    expect($message)->toContain('🎬 **Filmy**')->not->toContain('Movies');
});

test('a title no registered type names goes to its own section', function () {
    [$message] = digestOf([digestNotification(['type' => 'boardgame', 'title' => 'Wingspan'])]);

    expect($message)->toContain("✨ **Other**\n")->toContain('[Wingspan]');
});

test('a long digest splits into messages within Discord\'s limit, a section heading said once', function () {
    $many = array_map(fn (int $n) => digestNotification(['type' => 'movie', 'ref' => (string) $n, 'title' => "A rather long film title number {$n}"]), range(1, 60));

    $messages = digestOf($many);

    expect(count($messages))->toBeGreaterThan(1)
        ->and(max(array_map('strlen', $messages)))->toBeLessThanOrEqual(2000)
        ->and(substr_count(implode('', $messages), '🎬 **Movies**'))->toBe(1);
});

test('markdown in a title cannot break its link', function () {
    [$message] = digestOf([digestNotification(['type' => 'movie', 'title' => 'Star Wars [Remastered]*'])]);

    expect($message)->toContain('[Star Wars \[Remastered\]\*]');
});
