<?php

use App\Catalog\ReleaseRegion;

test('a country reads as the release region it belongs to', function (string $country, ReleaseRegion $region) {
    expect(ReleaseRegion::ofCountry($country))->toBe($region);
})->with([
    'Poland, in Europe' => ['PL', ReleaseRegion::Europe],
    'Turkey, in the PAL market' => ['TR', ReleaseRegion::Europe],
    'lowercase input' => ['de', ReleaseRegion::Europe],
    'the United States' => ['US', ReleaseRegion::NorthAmerica],
    'Mexico' => ['MX', ReleaseRegion::NorthAmerica],
    'Japan, a region of its own' => ['JP', ReleaseRegion::Japan],
    'New Zealand, a region of its own' => ['NZ', ReleaseRegion::NewZealand],
    'Singapore, the rest of Asia' => ['SG', ReleaseRegion::Asia],
    'Argentina, in no region' => ['AR', ReleaseRegion::Worldwide],
]);
