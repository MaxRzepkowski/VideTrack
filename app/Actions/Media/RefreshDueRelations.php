<?php

namespace App\Actions\Media;

use App\Catalog\Relations\RelationRefreshResult;
use App\Catalog\Relations\RelationStore;
use App\Support\ExternalServiceException;
use App\Tasks\ProgressReporter;
use Carbon\CarbonInterface;

/**
 * Asks the outside source about the stored titles that are due, a batch per
 * question, then lists the franchises they named, until the deadline. What
 * it does not reach stays due for the next run. A service that stops
 * answering ends the run: the rest waits for a day when it is up.
 */
final readonly class RefreshDueRelations
{
    /** Titles in one question: the source is slow per request, not per title. */
    public const BATCH = 25;

    /** Franchises in one question; each may bring dozens of titles to find. */
    public const FRANCHISE_BATCH = 10;

    public function __construct(
        private RefreshTitleRelations $refresh,
        private ListFranchiseTitles $listFranchises,
        private RelationStore $store,
    ) {}

    public function execute(CarbonInterface $deadline, string $locale, ProgressReporter $progress): RelationRefreshResult
    {
        $due = $this->store->countDue(now());
        $asked = 0;
        $ties = 0;
        $listed = 0;

        try {
            while ($asked < $due && now()->lessThan($deadline)) {
                $batch = $this->store->due(now(), self::BATCH);

                if ($batch->isEmpty()) {
                    break;
                }

                $ties += $this->refresh->execute($batch, $locale);
                $asked += $batch->count();
                $progress->report(fn (): string => __('tasks.progress_asking_about_titles', ['current' => $asked, 'total' => $due]));
            }

            // Each franchise once, however many of the titles share it.
            while (now()->lessThan($deadline)) {
                $franchises = $this->store->franchisesDue(now(), self::FRANCHISE_BATCH);

                if ($franchises === []) {
                    break;
                }

                $listed += $this->listFranchises->execute($franchises, $locale);
                $progress->report(fn (): string => __('tasks.progress_listing_franchises', ['count' => $listed]));
            }
        } catch (ExternalServiceException $e) {
            return new RelationRefreshResult($due, $asked, $ties, $listed, [$e->wording()]);
        }

        return new RelationRefreshResult($due, $asked, $ties, $listed, []);
    }
}
