<?php

namespace App\Jobs;

use App\Catalog\Contracts\SupportsTrending;
use App\Catalog\ProviderRegistry;
use App\Jobs\Concerns\RunsAsTask;
use App\Models\TaskRun;
use App\Support\ExternalServiceException;
use App\Tasks\TaskName;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Warms the provider cache behind the /discover trending sections
 * so first paint after cache expiry never waits on the
 * provider. One fetch per supported locale.
 */
class PrefetchTrending implements ShouldQueue
{
    use Dispatchable, Queueable, RunsAsTask;

    // The worker's default, stated: the overlap lock lapses with it.
    public int $timeout = 60;

    public int $maxExceptions = self::BREAKS_ALLOWED;

    public function __construct(private readonly TaskRun $taskRun) {}

    public static function queueTask(): void
    {
        self::dispatch(TaskRun::queueTask(TaskName::PrefetchTrending->value));
    }

    public function handle(ProviderRegistry $providers): void
    {
        $this->taskRun->markStarted();

        /** @var array<string, SupportsTrending> $supporting provider code => provider */
        $supporting = $providers->allSupporting(SupportsTrending::class);

        if ($supporting === []) {
            $this->taskRun->markSuccess(__('tasks.summary_no_trending'));

            return;
        }

        /** @var list<string> $locales */
        $locales = (array) config('tracker.supported_locales');
        $fetched = 0;
        $errors = [];

        // One /discover trending row per provider — warm every provider so the
        // first paint of each row is instant.
        foreach ($supporting as $code => $provider) {
            foreach ($locales as $locale) {
                try {
                    $fetched += count($provider->trending($locale));
                } catch (ExternalServiceException $e) {
                    $errors[] = "{$code} {$locale}: {$e->wording()}";
                }
            }
        }

        $this->taskRun->markSuccess(
            __('tasks.summary_trending', ['titles' => $fetched, 'locales' => count($locales)]),
            $errors,
        );
    }
}
