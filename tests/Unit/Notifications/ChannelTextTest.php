<?php

use App\Notifications\Channels\ChannelText;
use App\Notifications\Channels\Data\ChannelDigest;
use App\Notifications\Channels\Data\ChannelMessage;
use App\Notifications\Channels\Data\DigestLine;
use App\Notifications\Channels\Data\DigestSection;

function plainMessage(?string $detail): ChannelMessage
{
    return new ChannelMessage(title: 'Out today: Dune', detail: $detail, url: 'https://tracker.example.com/movie/1', imageUrl: null, color: 0, writtenAt: '2026-10-10T12:00:00+00:00');
}

test('a message is its facts over its link, or the link alone', function () {
    $text = new ChannelText;

    expect($text->message(plainMessage('S01E02 – Pilot (9:00 AM)')))->toBe("S01E02 – Pilot (9:00 AM)\nhttps://tracker.example.com/movie/1")
        ->and($text->message(plainMessage(null)))->toBe('https://tracker.example.com/movie/1');
});

test('a digest is its sections, each line with its link under it, and the closing line', function () {
    $digest = new ChannelDigest('Your daily digest', [
        new DigestSection('Shows', '📺', [new DigestLine('Severance', 'https://tracker.example.com/show/1', 'New episode', 'S02E03')]),
        new DigestSection('Other', null, [new DigestLine('2 titles are gone', 'https://tracker.example.com/archived', '', null)]),
    ], 'Enjoy your media!');

    expect((new ChannelText)->digest($digest))->toBe(implode("\n", [
        '📺 Shows',
        '• Severance: New episode: S02E03',
        '  https://tracker.example.com/show/1',
        '',
        'Other',
        '• 2 titles are gone',
        '  https://tracker.example.com/archived',
        '',
        'Enjoy your media!',
    ]));
});

test('a digest line without its link names the title, what happened and when', function () {
    $text = new ChannelText;

    expect($text->digestLine(new DigestLine('Severance', 'https://tracker.example.com/show/1', 'New episode', 'S02E03')))->toBe('Severance: New episode: S02E03')
        ->and($text->digestLine(new DigestLine('2 titles are gone', 'https://tracker.example.com/archived', '', null)))->toBe('2 titles are gone');
});
