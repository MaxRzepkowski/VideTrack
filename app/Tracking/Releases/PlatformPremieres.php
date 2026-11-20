<?php

namespace App\Tracking\Releases;

use App\Catalog\Data\PlatformRelease;
use App\Catalog\ReleaseRegion;

/**
 * A title's per-platform premieres as one viewer sees them in a window: one
 * date per platform and release kind out of the regional variants stored,
 * and the platforms premiering the same day as one premiere naming them all.
 */
final readonly class PlatformPremieres
{
    /**
     * @param  list<PlatformRelease>  $releases  every regional variant the title stores
     * @return list<PlatformPremiere> in the order their first platform was picked
     */
    public function inWindow(array $releases, ReleaseWindow $window): array
    {
        $premieres = [];

        foreach ($this->pickRegionalVariants($releases, $window->region) as $release) {
            if (! $window->holds($release->date, $release->precision)) {
                continue;
            }

            $day = $release->date.'|'.$release->precision->value.'|'.$release->status;
            $platforms = $premieres[$day]->platforms ?? [];

            if ($release->platform !== null && $release->platform !== '' && ! in_array($release->platform, $platforms, true)) {
                $platforms[] = $release->platform;
            }

            $premieres[$day] = new PlatformPremiere($release->date, $release->precision, $release->status, $platforms);
        }

        return array_values($premieres);
    }

    /**
     * The viewer's region picks one variant per platform + release kind —
     * their region first, then worldwide, then the earliest.
     *
     * @param  list<PlatformRelease>  $releases
     * @return list<PlatformRelease>
     */
    private function pickRegionalVariants(array $releases, ReleaseRegion $region): array
    {
        $rank = fn (PlatformRelease $release): array => [match ($release->region) {
            $region => 0,
            ReleaseRegion::Worldwide => 1,
            default => 9,
        }, $release->date];

        $picked = [];

        foreach ($releases as $release) {
            $key = $release->platform.'|'.$release->status;
            $current = $picked[$key] ?? null;

            if ($current === null || $rank($release) < $rank($current)) {
                $picked[$key] = $release;
            }
        }

        return array_values($picked);
    }
}
