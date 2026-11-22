<?php

namespace App\Actions\Media;

use App\Catalog\ProviderBusy;
use App\Catalog\Relations\RelationRefreshResult;
use App\Catalog\Relations\RelationStore;
use App\Catalog\Wikidata\TitleItems;
use App\Library\LibraryHolders;
use App\Support\ExternalServiceException;
use App\Tasks\ProgressReporter;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Sleep;

/**
 * Asks the outside source about the stored titles that are due, a batch per
 * question, the ones someone holds first, then lists the franchises they
 * named, until the deadline. What it does not reach stays due for the next
 * run. A service's limit is waited out; a batch that fails is asked again in
 * halves down to the one title behind it, which waits for the next run; a
 * service that fails title after title ends the run, and the rest waits for
 * a run when it is up.
 */
final readonly class RefreshDueRelations
{
    /** Titles in one question: the source is slow per request, not per title. */
    public const BATCH = 25;

    /** Franchises in one question; each may bring dozens of titles to find. */
    public const FRANCHISE_BATCH = 10;

    /**
     * Hours a title the source failed to answer about waits before it is
     * asked again: less than a day, so the next morning's run has it.
     */
    public const HOURS_AFTER_FAILURE = 20;

    /** Titles failing one after another before the run takes the service for down. */
    public const FAILURES_BEFORE_STOP = 3;

    public function __construct(
        private RefreshTitleRelations $refresh,
        private ListFranchiseTitles $listFranchises,
        private RelationStore $store,
        private TitleItems $items,
        private LibraryHolders $holders,
    ) {}

    public function execute(CarbonInterface $deadline, string $locale, ProgressReporter $progress): RelationRefreshResult
    {
        $types = $this->items->findableTypes();
        $due = $this->store->countDue(now(), $types);
        $asked = 0;
        $ties = 0;
        $listed = 0;
        $errors = [];
        $size = self::BATCH;
        $failedInARow = 0;
        // The titles someone holds come first, a window of them at a time:
        // the rest of the catalogue only has its turn once they are covered.
        $held = array_values($this->holders->rootsHeldByAnyone()->all());
        $window = 0;

        while ($asked < $due && now()->lessThan($deadline)) {
            $batch = new Collection;

            while ($batch->isEmpty() && $window < count($held)) {
                $batch = $this->store->dueOf(now(), $size, $types, array_slice($held, $window, RelationStore::CHUNK));
                $window += $batch->isEmpty() ? RelationStore::CHUNK : 0;
            }

            if ($batch->isEmpty()) {
                $batch = $this->store->due(now(), $size, $types);
            }

            if ($batch->isEmpty()) {
                break;
            }

            try {
                $ties += $this->refresh->execute($batch, $locale);
            } catch (ProviderBusy $e) {
                // A service's own limit is waited out, unless that outlasts the run.
                if (now()->addSeconds($e->retryAfterSeconds)->greaterThanOrEqualTo($deadline)) {
                    return new RelationRefreshResult($due, $asked, $ties, $listed, [...$errors, $e->wording()]);
                }

                Sleep::for($e->retryAfterSeconds)->seconds();

                continue;
            } catch (ExternalServiceException $e) {
                // One title can make a whole batch's question too heavy to
                // answer: asked in halves, it is found and set aside until
                // the next run, so it never holds up the runs that follow.
                if ($batch->count() > 1) {
                    $size = intdiv($batch->count(), 2);

                    continue;
                }

                $title = $batch->firstOrFail();
                $this->store->postpone($title, now()->addHours(self::HOURS_AFTER_FAILURE));
                $errors[] = "{$title->title}: {$e->wording()}";
                $asked++;

                // Failing title after title, it is the service that is down.
                if (++$failedInARow >= self::FAILURES_BEFORE_STOP) {
                    return new RelationRefreshResult($due, $asked, $ties, $listed, $errors);
                }

                continue;
            }

            [$size, $failedInARow] = [self::BATCH, 0];
            $asked += $batch->count();
            $progress->report(fn (): string => __('tasks.progress_asking_about_titles', ['current' => $asked, 'total' => $due]));
        }

        try {
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
            $errors[] = $e->wording();
        }

        return new RelationRefreshResult($due, $asked, $ties, $listed, $errors);
    }
}
