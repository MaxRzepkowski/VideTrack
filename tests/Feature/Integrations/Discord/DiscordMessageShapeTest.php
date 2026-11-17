<?php

use App\Actions\Media\SendMediaNotification;
use App\Models\User;
use App\Notifications\Channels\ChannelMode;
use App\Notifications\MediaEventPayload;
use Illuminate\Support\Facades\Http;

/*
 * What Discord receives, to the character: the embed of an instant message
 * and the text of a digest. Any change here is a change users see in their
 * channel.
 */

function airedEpisodeEvent(): MediaEventPayload
{
    return MediaEventPayload::fromStored([
        'key' => 'episode:1',
        'event' => 'episode_aired',
        'showTitle' => 'Severance',
        'date' => '2026-10-10',
        'media' => [
            'type' => 'episode',
            'ref' => '1:2:3',
            'provider' => 'tmdb',
            'title' => 'Woe’s Hollow',
            'number' => 3,
            'posterPath' => '/poster.jpg',
            'releaseTime' => '2026-10-10T07:00:00Z',
            'extra' => ['seasonNumber' => 2],
        ],
    ]);
}

test('an instant message is one embed with the headline, the facts, the air time and the picture', function () {
    Http::fake(['discord.com/*' => Http::response(null, 204)]);
    $user = User::factory()->create(['locale' => 'pl', 'preferences' => ['timezone' => 'Europe/Warsaw']]);
    discordChannel($user, ChannelMode::Instant);

    app(SendMediaNotification::class)->execute($user, airedEpisodeEvent());

    expect(Http::recorded()->first()[0]->data())->toBe(['embeds' => [[
        'title' => 'Nowy odcinek Severance',
        'url' => route('episode.details', ['ref' => 1, 'number' => 2, 'episode' => 3]),
        'color' => 0x8136FF,
        'timestamp' => now()->toIso8601String(),
        'description' => 'S02E03 – Woe’s Hollow (09:00)',
        'thumbnail' => ['url' => 'https://image.tmdb.org/t/p/w500/poster.jpg'],
    ]]]);
});

test('a digest is plain text: a header, a section per kind of title, a closing line', function () {
    Http::fake(['discord.com/*' => Http::response(null, 204)]);
    $this->travelTo('2026-10-10 05:00:00');
    $user = User::factory()->create(['preferences' => ['timezone' => 'Europe/Warsaw']]);
    discordChannel($user);

    app(SendMediaNotification::class)->execute($user, airedEpisodeEvent());
    app(SendMediaNotification::class)->execute($user, premiereEvent('Dune [Part *Two*]'));
    $this->travelTo('2026-10-10 07:00:00');

    runChannelDigests();

    expect(Http::recorded()->first()[0]->data())->toBe(['content' => implode("\n", [
        '📬 **Your daily digest**',
        '',
        '📺 **Shows**',
        '• **[Severance](<'.route('episode.details', ['ref' => 1, 'number' => 2, 'episode' => 3]).'>)**: New episode: S02E03 – Woe’s Hollow (9:00 AM)',
        '',
        '🎬 **Movies**',
        '• **[Dune \\[Part \\*Two\\*\\]](<'.route('movie.details', ['ref' => 'tmdb:1']).'>)**: Out today',
        '',
        'Enjoy your media!',
    ])]);
});
