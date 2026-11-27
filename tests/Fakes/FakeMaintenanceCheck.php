<?php

namespace Tests\Fakes;

use App\Tasks\Data\TaskLine;
use App\Tasks\Maintenance\CheckLevel;
use App\Tasks\Maintenance\CheckReport;
use App\Tasks\Maintenance\Contracts\MaintenanceCheck;
use App\Tasks\Maintenance\Data\CheckLine;
use App\Tasks\Maintenance\MaintenanceWindow;
use Throwable;

/**
 * A check that does what the test told it to: says what is well, notes
 * something, or breaks. Every run of a fake joins one shared log, so a test
 * can read the order checks ran in.
 */
class FakeMaintenanceCheck implements MaintenanceCheck
{
    public const CODE = 'fake-check';

    /** @var list<class-string> the fakes that ran, in order */
    public static array $ran = [];

    public static MaintenanceWindow $window = MaintenanceWindow::Nightly;

    public static int $order = 10;

    public static bool $applies = true;

    /** @var list<string> the results it says, as translation keys */
    public static array $results = ['tasks.fake_check_result'];

    /** @var list<string> the notes it makes, as translation keys */
    public static array $notes = [];

    public static ?Throwable $failure = null;

    public function label(): string
    {
        return 'Fake check';
    }

    public function window(): MaintenanceWindow
    {
        return static::$window;
    }

    public function order(): int
    {
        return static::$order;
    }

    public function applies(): bool
    {
        return static::$applies;
    }

    public function run(CheckReport $report): void
    {
        self::$ran[] = static::class;

        foreach (static::$results as $key) {
            $report->add(new CheckLine(CheckLevel::Ok, new TaskLine($key)));
        }

        foreach (static::$notes as $key) {
            $report->note($key);
        }

        if (static::$failure !== null) {
            throw static::$failure;
        }
    }
}
