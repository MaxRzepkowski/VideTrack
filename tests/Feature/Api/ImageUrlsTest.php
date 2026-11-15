<?php

use App\Catalog\Data\MediaSummary;
use App\Catalog\ImageUrls;
use App\Http\Resources\Api\V1\MediaSummaryResource;

test('a provider path hangs off the image CDN in the asked size, a whole URL passes through', function () {
    config(['tracker.tmdb.image_base_url' => 'https://image.tmdb.org/t/p/']);

    expect(ImageUrls::of('/abc.jpg', ImageUrls::POSTER))->toBe('https://image.tmdb.org/t/p/w500/abc.jpg')
        ->and(ImageUrls::of('https://covers.example/1.jpg', ImageUrls::POSTER))->toBe('https://covers.example/1.jpg')
        ->and(ImageUrls::of(null, ImageUrls::BACKDROP))->toBeNull()
        ->and(ImageUrls::of('', ImageUrls::BACKDROP))->toBeNull();
});

test('an API card carries its images as loadable addresses beside the paths', function () {
    config(['tracker.tmdb.image_base_url' => 'https://image.tmdb.org/t/p']);
    $card = (new MediaSummaryResource(new MediaSummary(type: 'movie', ref: '1', provider: 'tmdb', title: 'Dune', posterPath: '/p.jpg', backdropPath: '/b.jpg')))->toArray(request());

    expect($card['posterPath'])->toBe('/p.jpg')
        ->and($card['posterUrl'])->toBe('https://image.tmdb.org/t/p/w500/p.jpg')
        ->and($card['backdropUrl'])->toBe('https://image.tmdb.org/t/p/w1280/b.jpg');
});
