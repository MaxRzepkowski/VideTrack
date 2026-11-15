<?php

namespace App\Catalog\Contracts;

use App\Catalog\Department;

/**
 * Capability: the provider can say which of the jobs it credits in a
 * department belong to the person a title is "by" (its director, not an
 * assistant; who wrote it, not the author of the novel it adapts). A job's
 * name is the provider's own word, stored with the credit as it came, so
 * only the provider can tell the lead from the rest.
 */
interface SupportsCrewRoles
{
    /**
     * @return list<string> the job names, as this provider writes them; empty for a department it singles nobody out in
     */
    public function leadJobs(Department $department): array;
}
