<?php

namespace App\Jobs;

use App\Catalog\Creators\CompanyRecords;
use App\Catalog\Creators\CreditRecords;
use App\Catalog\Creators\PersonRecords;
use App\Catalog\Creators\UnknownCreator;
use App\Jobs\Concerns\RunsAsTask;
use App\Models\Company;
use App\Models\Follow;
use App\Models\ListItem;
use App\Models\Person;
use App\Models\TaskRun;
use App\Support\ExternalServiceException;
use App\Support\NotFoundAtService;
use App\Tasks\ProgressReporter;
use App\Tasks\TaskName;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Keeps stored people and companies current — the entity counterpart of
 * the cycle in {@see RefreshCatalog}.
 *
 * Both tables are written on first sight and then trusted, because a request
 * must not spend a provider call on a row it already has. Without a sweep a
 * row keeps whatever the provider said the day it appeared — and when a provider moves an image file, the dead
 * path stays in the row: broken portraits and logos on every card, while the
 * entity's own page, rendered from live data, looks perfectly fine.
 *
 * Scope:
 *  - every company row, because a company only exists here after someone
 *    followed it or put it on a list — there are no incidental ones;
 *  - people someone attached to (a follow, a list). The thousands of others
 *    come from credits and are rewritten from each title's own payload
 *    whenever its metadata refreshes ({@see CreditRecords}),
 *    so sweeping them would buy nothing for thousands of requests.
 */
class RefreshEntityMetadata implements ShouldQueue
{
    use Dispatchable, Queueable, RunsAsTask;

    /**
     * One rate-limited provider call per row, like the media sweep — and for
     * the same reason its ceiling matches the longest job in the app.
     */
    public int $timeout = 3600;

    public int $maxExceptions = self::BREAKS_ALLOWED;

    public function __construct(private readonly TaskRun $taskRun) {}

    /** Creates the pending task_runs record and queues the job. */
    public static function queueTask(): void
    {
        self::dispatch(TaskRun::queueTask(TaskName::RefreshEntities->value));
    }

    public function handle(PersonRecords $people, CompanyRecords $companies): void
    {
        $this->taskRun->markStarted();
        $progress = new ProgressReporter($this->taskRun);

        $locale = config()->string('tracker.default_locale');
        $companyRows = Company::query()->orderBy('id')->get();
        $personRows = $this->attachedPeople();
        $total = $companyRows->count() + $personRows->count();

        $refreshed = 0;
        $processed = 0;
        $errors = [];
        $kept = [];

        foreach ($companyRows as $company) {
            try {
                $companies->refresh($company, $locale);
                $refreshed++;
            } catch (UnknownCreator|NotFoundAtService $e) {
                // A provider without company pages (or no longer configured
                // here), or an entity merged away upstream: noted, never
                // deleted, and nothing went wrong. Follows and list entries
                // hang off these rows, and a 404 is as often a merge as a
                // removal — the same reason the media sweep keeps what users
                // attached to.
                $kept[] = $this->kept("{$company->kind}:{$company->provider_ref} ({$company->name})", $e);
            } catch (ExternalServiceException $e) {
                $errors[] = "{$company->kind}:{$company->provider_ref} ({$company->name}): {$e->wording()}";
            }

            $processed++;
            $progress->report(fn (): string => __('tasks.progress_refreshing_creator', ['current' => $processed, 'total' => $total]));
        }

        foreach ($personRows as $person) {
            try {
                $people->refresh($person, $locale);
                $refreshed++;
            } catch (UnknownCreator|NotFoundAtService $e) {
                $kept[] = $this->kept("person:{$person->provider_ref} ({$person->name})", $e);
            } catch (ExternalServiceException $e) {
                $errors[] = "person:{$person->provider_ref} ({$person->name}): {$e->wording()}";
            }

            $processed++;
            $progress->report(fn (): string => __('tasks.progress_refreshing_creator', ['current' => $processed, 'total' => $total]));
        }

        $summary = "Refreshed {$refreshed} of {$total} people and companies.";

        if ($kept !== []) {
            $summary .= ' '.count($kept).' kept as they were.';
        }

        $this->taskRun->markSuccess($summary, $errors, $kept);
    }

    private function kept(string $label, UnknownCreator|NotFoundAtService $e): string
    {
        return $e instanceof NotFoundAtService
            ? "{$label}: no longer at the provider (kept: someone follows it or has it on a list)."
            : "{$label}: its provider is not configured here (kept as it was).";
    }

    /**
     * People a user reached for themselves: followed, or on a list.
     *
     * @return Collection<int, Person>
     */
    private function attachedPeople(): Collection
    {
        return Person::query()
            ->where(fn ($query) => $query
                ->whereIn('id', Follow::query()->whereNotNull('person_id')->distinct()->pluck('person_id'))
                ->orWhereIn('id', ListItem::query()->whereNotNull('person_id')->distinct()->pluck('person_id')))
            ->orderBy('id')
            ->get();
    }
}
