<?php

namespace App\Catalog;

use App\Catalog\Data\DataSourceCredit;

/**
 * Whom this instance's data comes from, credited on the About page in the
 * order the modules register. A module that is off adds nothing.
 */
final class DataSources
{
    /** @var list<DataSourceCredit> */
    private array $credits = [];

    public function credit(DataSourceCredit ...$credits): void
    {
        foreach ($credits as $credit) {
            $this->credits[] = $credit;
        }
    }

    /**
     * @return list<DataSourceCredit>
     */
    public function all(): array
    {
        return $this->credits;
    }
}
