<?php

namespace App\Tasks\Maintenance;

use App\Support\ServiceRegistry;
use App\Tasks\Maintenance\Contracts\MaintenanceCheck;

/**
 * The maintenance checks of this instance, registered by the modules they
 * look after.
 *
 * @extends ServiceRegistry<MaintenanceCheck>
 */
final class MaintenanceRegistry extends ServiceRegistry
{
    protected function kind(): string
    {
        return 'maintenance check';
    }

    /**
     * The checks that apply to this instance, by code, in the order they run:
     * window by window, and by their order within it.
     *
     * @return array<string, MaintenanceCheck>
     */
    public function applicable(?MaintenanceWindow $window = null): array
    {
        $checks = [];

        foreach ($this->codes() as $code) {
            $check = $this->get($code);

            if ($check->applies() && ($window === null || $check->window() === $window)) {
                $checks[$code] = $check;
            }
        }

        $windows = array_flip(array_map(fn (MaintenanceWindow $case): string => $case->value, MaintenanceWindow::cases()));

        uasort($checks, fn (MaintenanceCheck $a, MaintenanceCheck $b): int => [$windows[$a->window()->value], $a->order()] <=> [$windows[$b->window()->value], $b->order()]);

        return $checks;
    }
}
