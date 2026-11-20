<?php

use App\Catalog\Data\PlatformRelease;
use App\Catalog\DatePrecision;
use App\Catalog\ReleaseRegion;
use App\Models\User;
use App\Tracking\Releases\PlatformPremiere;
use App\Tracking\Releases\PlatformPremieres;
use App\Tracking\Releases\ReleaseWindow;
use App\Tracking\Surface;

function premieresWindow(Surface $surface = Surface::Calendar, ReleaseRegion $region = ReleaseRegion::Europe): ReleaseWindow
{
    return new ReleaseWindow(new User, '2026-11-01', '2026-11-30', $surface, $surface->showsApproximateDates(), $region);
}

test('the viewer\'s region picks one date per platform and kind, then worldwide, then the earliest', function () {
    $premieres = (new PlatformPremieres)->inWindow([
        new PlatformRelease('2026-11-20', 'Switch', null, ReleaseRegion::NorthAmerica),
        new PlatformRelease('2026-11-10', 'Switch', null, ReleaseRegion::Europe),
        new PlatformRelease('2026-11-12', 'PC', null, ReleaseRegion::Worldwide),
        new PlatformRelease('2026-11-05', 'PC', null, ReleaseRegion::Japan),
        new PlatformRelease('2026-11-25', 'PS5', null, ReleaseRegion::Japan),
        new PlatformRelease('2026-11-22', 'PS5', null, ReleaseRegion::Korea),
    ], premieresWindow());

    expect(array_map(fn (PlatformPremiere $premiere): string => "{$premiere->cardPlatform()} {$premiere->date}", $premieres))
        ->toEqualCanonicalizing(['Switch 2026-11-10', 'PC 2026-11-12', 'PS5 2026-11-22']);
});

test('platforms premiering the same day as the same kind are one premiere naming them all', function () {
    $premieres = (new PlatformPremieres)->inWindow([
        new PlatformRelease('2026-11-10', 'PC'),
        new PlatformRelease('2026-11-10', 'PS5'),
        new PlatformRelease('2026-11-10', 'Switch 2', 'Next-Gen Optimization Patch Release'),
        new PlatformRelease('2026-11-18', 'Xbox'),
    ], premieresWindow());

    expect(array_map(fn (PlatformPremiere $premiere): array => [$premiere->date, $premiere->platforms, $premiere->status], $premieres))->toEqual([
        ['2026-11-10', ['PC', 'PS5'], null],
        ['2026-11-10', ['Switch 2'], 'Next-Gen Optimization Patch Release'],
        ['2026-11-18', ['Xbox'], null],
    ]);
});

test('only what the window holds is left: an approximate date only where the surface takes those', function () {
    $releases = [
        new PlatformRelease('2026-10-20', 'PC'),
        new PlatformRelease('2026-11-30', 'PS5', precision: DatePrecision::Month),
        new PlatformRelease('2026-11-15', 'Switch'),
    ];

    $platforms = fn (Surface $surface): array => array_map(
        fn (PlatformPremiere $premiere): ?string => $premiere->cardPlatform(),
        (new PlatformPremieres)->inWindow($releases, premieresWindow($surface)),
    );

    expect($platforms(Surface::Calendar))->toBe(['Switch'])
        ->and($platforms(Surface::Countdowns))->toBe(['PS5', 'Switch']);
});
