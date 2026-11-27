<?php

namespace App\Tasks;

use App\Support\ExternalServiceException;
use App\Tasks\Data\TaskLine;
use Throwable;

/**
 * How a background run that broke says so. An outside service's failure is
 * worded by us and safe to show; any other exception may carry a query, a
 * path or an address, so the run says only that it broke — the log has the rest.
 */
final class RunFailure
{
    /** A translation key: the run's summary is worded in the language it is read in. */
    public const UNEXPECTED = 'tasks.the_run_stopped_on_an_unexpected_error';

    public static function of(Throwable $exception): TaskLine
    {
        return $exception instanceof ExternalServiceException
            ? new TaskLine($exception->key(), $exception->context())
            : self::unexpected();
    }

    public static function unexpected(): TaskLine
    {
        return new TaskLine(self::UNEXPECTED);
    }
}
