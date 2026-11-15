<?php

namespace App\Actions\Suggestions;

use App\Catalog\ItemNotFoundException;
use App\Catalog\Sets\SetFetcher;
use App\Catalog\Sets\SetStore;
use App\Models\User;
use App\Suggestions\Data\SetRefreshResult;
use App\Suggestions\SuggestionSources;
use App\Support\ExternalServiceException;
use App\Tasks\ProgressReporter;
use Carbon\CarbonInterface;

/**
 * Brings the provider sets the suggestion shelves read up to date: notes
 * which sets every account's shelves need, copies the missing and the aged
 * ones from their providers until the deadline, and forgets the ones nobody
 * has needed for a long while. What the deadline cuts off stays due for the
 * next run.
 */
final readonly class FetchWantedSets
{
    public function __construct(
        private SuggestionSources $sources,
        private SetStore $sets,
        private SetFetcher $fetcher,
    ) {}

    public function execute(CarbonInterface $deadline, ProgressReporter $progress): SetRefreshResult
    {
        // Most sets are wanted by many accounts (the charts by all of them):
        // gathered first, each is noted once per language.
        $wanted = [];

        foreach (User::query()->lazyById() as $user) {
            foreach ($this->sources->all() as $source) {
                foreach ($source->wantedSets($user) as $key) {
                    $wanted[$user->preferredLocale()][$key->id()] = $key;
                }
            }
        }

        foreach ($wanted as $locale => $keys) {
            $this->sets->want(array_values($keys), $locale);
        }

        $due = $this->sets->due(now());
        $total = $due->count();
        $fetched = 0;
        $gone = 0;
        $errors = [];
        // A provider that failed once is left alone for the rest of the run.
        $stopped = [];

        foreach ($due->values() as $index => $set) {
            if (now()->greaterThanOrEqualTo($deadline)) {
                break;
            }

            if (isset($stopped[$set->provider])) {
                continue;
            }

            try {
                $answer = $this->fetcher->fetch($set->key(), $set->locale);

                if ($answer === null) {
                    $this->sets->forget($set);
                } else {
                    $this->sets->fill($set, $answer);
                    $fetched++;
                }
            } catch (ItemNotFoundException) {
                $this->sets->forget($set);
                $gone++;
            } catch (ExternalServiceException $e) {
                $stopped[$set->provider] = true;
                $errors[] = "{$set->provider}: {$e->wording()}";
            }

            $progress->report(fn (): string => __('tasks.progress_fetching_set', ['current' => $index + 1, 'total' => $total]));
        }

        return new SetRefreshResult($total, $fetched, $gone, $this->sets->forgetUnwanted(now()), $errors);
    }
}
