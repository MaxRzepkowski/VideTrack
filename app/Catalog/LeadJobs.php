<?php

namespace App\Catalog;

use App\Catalog\Contracts\SupportsCrewRoles;

/**
 * A department's lead jobs (see SupportsCrewRoles) as every provider of this
 * instance writes them on the credits it stores, each name once.
 */
final readonly class LeadJobs
{
    public function __construct(private ProviderRegistry $providers) {}

    /** @return list<string> empty for a department no provider singles anybody out in */
    public function in(Department $department): array
    {
        $jobs = [];

        foreach ($this->providers->allSupporting(SupportsCrewRoles::class) as $provider) {
            if ($provider instanceof SupportsCrewRoles) {
                array_push($jobs, ...$provider->leadJobs($department));
            }
        }

        return array_values(array_unique($jobs));
    }
}
