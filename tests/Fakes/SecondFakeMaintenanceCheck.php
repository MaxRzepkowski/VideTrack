<?php

namespace Tests\Fakes;

use App\Tasks\Maintenance\MaintenanceWindow;
use Throwable;

/** A second check with a behaviour of its own, for what happens between checks. */
class SecondFakeMaintenanceCheck extends FakeMaintenanceCheck
{
    public const CODE = 'second-fake-check';

    public static MaintenanceWindow $window = MaintenanceWindow::Nightly;

    public static int $order = 20;

    public static bool $applies = true;

    /** @var list<string> */
    public static array $results = ['tasks.fake_check_result'];

    /** @var list<string> */
    public static array $notes = [];

    public static ?Throwable $failure = null;

    public function label(): string
    {
        return 'Second fake check';
    }
}
