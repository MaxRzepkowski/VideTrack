<?php

namespace App\Tasks\Maintenance\Checks;

use App\Catalog\Contracts\SupportsHealthProbe;
use App\Catalog\Data\ProviderFailure;
use App\Catalog\Data\ProviderStatus;
use App\Catalog\ProviderHealth;
use App\Catalog\ProviderRegistry;
use App\Catalog\ProviderState;
use App\Support\ExternalServiceException;
use App\Tasks\Data\TaskLine;
use App\Tasks\Maintenance\CheckLevel;
use App\Tasks\Maintenance\CheckReport;
use App\Tasks\Maintenance\Contracts\MaintenanceCheck;
use App\Tasks\Maintenance\Data\CheckLine;
use App\Tasks\Maintenance\MaintenanceWindow;
use App\Tasks\Maintenance\RunCause;
use Carbon\CarbonImmutable;

/**
 * Whether each metadata provider answers and takes the instance's key, one
 * line each. The requests users and tasks send already tell; a provider
 * nothing has asked for a quarter of an hour is asked once, cheaply, so an
 * idle instance knows too. A refused key is asked about again only after an
 * hour, an answered refusal is not repeated in a series, unless the admin
 * runs the check: then each provider is asked once, to show what a new key did.
 */
final readonly class ProviderProbe implements MaintenanceCheck
{
    public const CODE = 'provider-health';

    /** A provider that answered within this long needs no probe. */
    public const QUIET_SECONDS = 900;

    public const REFUSED_PROBE_EVERY_SECONDS = 3600;

    public function __construct(
        private ProviderRegistry $providers,
        private ProviderHealth $health,
    ) {}

    public function label(): string
    {
        return __('tasks.provider_health');
    }

    public function window(): MaintenanceWindow
    {
        return MaintenanceWindow::Frequent;
    }

    public function order(): int
    {
        return 10;
    }

    public function applies(): bool
    {
        return $this->providers->codesImplementing(SupportsHealthProbe::class) !== [];
    }

    public function run(CheckReport $report): void
    {
        $now = now()->toImmutable();

        foreach ($this->providers->allSupporting(SupportsHealthProbe::class) as $code => $provider) {
            if (! $provider instanceof SupportsHealthProbe) {
                continue;
            }

            if ($report->cause === RunCause::Admin || $this->due($this->health->of($code), $now)) {
                $this->probe($code, $provider);
            }

            $line = $this->line($code, $provider, $this->health->of($code));
            $report->add($line);

            $refused = $line->level === CheckLevel::Problem;
            $wasRefused = $report->previous($code)?->level === CheckLevel::Problem;

            if ($refused && ! $wasRefused) {
                $report->note('tasks.the_key_to_provider_stopped_working', ['provider' => $provider->serviceName()]);
            } elseif ($wasRefused && ! $refused) {
                $report->note('tasks.the_key_to_provider_works_again', ['provider' => $provider->serviceName()]);
            }
        }
    }

    private function line(string $code, SupportsHealthProbe $provider, ProviderStatus $status): CheckLine
    {
        $name = $provider->serviceName();
        $named = ['provider' => $name];
        $answered = $status->answeredAt === null ? [] : [new TaskLine('tasks.last_answered_at', moments: ['time' => $status->answeredAt])];
        $failure = $status->failure === null ? [] : [$this->failure($status->failure)];

        return match ($status->state) {
            ProviderState::Healthy => new CheckLine(CheckLevel::Ok, new TaskLine('tasks.provider_answers', $named), subject: $code, label: $name, state: new TaskLine('tasks.provider_state_answers')),
            ProviderState::Degraded => new CheckLine(
                CheckLevel::Warning,
                new TaskLine('tasks.provider_is_not_answering', $named),
                [...$failure, ...$answered],
                $code,
                label: $name,
                state: new TaskLine('tasks.provider_state_not_answering'),
            ),
            ProviderState::KeyRejected => new CheckLine(CheckLevel::Problem, new TaskLine('tasks.provider_refuses_the_key', $named), [
                ...$failure,
                ...$answered,
                new TaskLine('tasks.titles_from_provider_stop_refreshing'),
                new TaskLine('tasks.put_a_new_key_in_variables', ['variables' => implode(', ', $provider->credentialVariables())]),
            ], $code, $status->since, $name, new TaskLine('tasks.provider_state_refuses_the_key')),
        };
    }

    private function failure(ProviderFailure $failure): TaskLine
    {
        $at = ['time' => $failure->at];

        return match (true) {
            $failure->status !== null && $failure->reason !== null => new TaskLine('tasks.answered_status_reason_at', ['status' => $failure->status, 'reason' => $failure->reason], $at),
            $failure->status !== null => new TaskLine('tasks.answered_status_at', ['status' => $failure->status], $at),
            default => new TaskLine('tasks.did_not_answer_at', moments: $at),
        };
    }

    private function due(ProviderStatus $status, CarbonImmutable $now): bool
    {
        if ($status->state === ProviderState::KeyRejected) {
            return $status->probedAt === null || $status->probedAt->diffInSeconds($now) >= self::REFUSED_PROBE_EVERY_SECONDS;
        }

        return $status->answeredAt === null || $status->answeredAt->diffInSeconds($now) >= self::QUIET_SECONDS;
    }

    private function probe(string $code, SupportsHealthProbe $provider): void
    {
        try {
            $provider->probe();
        } catch (ExternalServiceException) {
            // Any answer, or none, is the health's to keep; the run reads it from there.
        } finally {
            $this->health->probed($code);
        }
    }
}
