<?php

namespace App\Actions\Media;

use App\Catalog\GoneTitleFate;
use App\Catalog\Hierarchy;
use App\Catalog\ItemNotFoundException;
use App\Catalog\MediaTypeRegistry;
use App\Catalog\ProviderBusy;
use App\Catalog\ProviderCacheContext;
use App\Catalog\ScheduleFingerprint;
use App\Library\LibraryHolders;
use App\Models\MediaItem;
use App\Support\ExternalServiceException;
use App\Support\ServiceRejected;
use App\Tasks\CatalogRefresh\AiringCheck;
use App\Tasks\CatalogRefresh\RefreshQueue;
use App\Tasks\CatalogRefresh\RefreshReason;
use App\Tasks\CatalogRefresh\RefreshTally;
use App\Tasks\ProgressReporter;
use Carbon\CarbonInterface;

/**
 * Asks providers about the waiting roots, most urgent first, until the
 * deadline or the queue runs out. What goes wrong is told apart: a root gone
 * from its provider is retired; one failure leaves the root waiting until
 * tomorrow; a provider that fails several roots in a row, or refuses us, is
 * not asked again this run; a provider that asks to be waited out is left
 * alone until then while the others carry on.
 *
 * It also measures, without changing what it does: for a type whose list of
 * changes is not complete, whether the list named each title whose schedule
 * the refresh changed; for a title with parts, whether the stored tree
 * agrees with the title's own word on its latest and next part.
 */
final readonly class RefreshDueTitles
{
    /** Roots read from the queue at a time; the deadline is checked between them. */
    private const TAKE = 25;

    /** Failures in a row after which a provider is not asked again this run. */
    public const FAILURES_BEFORE_STOP = 3;

    /** How long a root the provider failed for waits before it is asked again. */
    public const HOURS_AFTER_FAILURE = 24;

    public function __construct(
        private RefreshQueue $queue,
        private RefreshTitle $refreshTitle,
        private RetireGoneTitle $retire,
        private LibraryHolders $holders,
        private Hierarchy $hierarchy,
        private ProviderCacheContext $cache,
        private ScheduleFingerprint $fingerprint,
        private AiringCheck $airing,
        private MediaTypeRegistry $types,
    ) {}

    public function execute(RefreshTally $tally, CarbonInterface $deadline, ProgressReporter $progress): RefreshTally
    {
        // One fresh scope for the batch: every address is fetched anew once,
        // so a provider's dictionaries are not asked again for each root.
        return $this->cache->fresh(function () use ($tally, $deadline, $progress): RefreshTally {
            $locale = config()->string('tracker.default_locale');

            while (now()->lt($deadline)) {
                $batch = $this->queue->take(self::TAKE, $tally->skippedAt(now()->getTimestamp()));

                if ($batch->isEmpty()) {
                    break;
                }

                foreach ($batch as $waiting) {
                    if (now()->gte($deadline)) {
                        break 2;
                    }

                    // A provider stopped or waited for mid-batch keeps its roots waiting.
                    if (in_array($waiting->item->provider, $tally->skippedAt(now()->getTimestamp()), true)) {
                        continue;
                    }

                    // Named before it is asked, so a title that takes long is the one the page shows.
                    $progress->reportNow(__('tasks.progress_refreshing_title', ['current' => $tally->processed + 1, 'total' => $tally->total(), 'title' => $waiting->item->title]));
                    $tally = $this->refresh($waiting->item, $waiting->reason ?? RefreshReason::Cycle, $locale, $tally);
                }
            }

            return $tally;
        });
    }

    private function refresh(MediaItem $item, RefreshReason $reason, string $locale, RefreshTally $tally): RefreshTally
    {
        $name = "{$item->type}:{$item->provider_ref} ({$item->title})";

        $measured = in_array($item->type, $tally->measuredTypes, true);
        $before = $measured ? $this->fingerprint->of($item) : null;

        try {
            memory_reset_peak_usage();
            $notified = $this->refreshTitle->execute($item, $locale);
            $tally = $tally->withRefreshed($reason, $item->provider, $notified)->withHeavy($this->heaviness($name));
        } catch (ItemNotFoundException) {
            return $this->retire($item, $name, $tally);
        } catch (ProviderBusy $e) {
            return $tally->withBusy($item->provider, now()->addSeconds($e->retryAfterSeconds)->getTimestamp());
        } catch (ServiceRejected $e) {
            return $tally->withRefusal($item->provider, "{$name}: {$e->wording()}");
        } catch (ExternalServiceException $e) {
            $this->queue->postpone($item->id, now()->addHours(self::HOURS_AFTER_FAILURE));

            return $tally->withFailure($item->provider, "{$name}: {$e->wording()}", self::FAILURES_BEFORE_STOP);
        }

        if ($before !== null) {
            $tally = $tally->withMeasured($item->type, $item->id, $before !== $this->fingerprint->of($item), $name);
        }

        return in_array($item->type, $this->types->containerRootCodes(), true)
            ? $tally->withObservation($this->airing->disagreement($item, $locale))
            : $tally;
    }

    /** A note when the refresh just done took the worker's memory past the threshold. */
    private function heaviness(string $name): ?string
    {
        $megabytes = (int) ceil(memory_get_peak_usage() / 1_048_576);

        return $megabytes > config()->integer('tracker.heavy_title_megabytes')
            ? "{$name}: the worker's memory peaked at {$megabytes} MB while refreshing it."
            : null;
    }

    private function retire(MediaItem $item, string $name, RefreshTally $tally): RefreshTally
    {
        $fate = $this->retire->execute($item);

        if ($fate === GoneTitleFate::Deleted) {
            return $tally->withRemoved("{$name}: removed (gone from the provider, no user data).");
        }

        // The provider did answer, if only to say it is gone.
        $this->queue->recordAsked($item->id);

        $archivedFor = $fate === GoneTitleFate::Archived
            ? array_values($this->holders->userIds($this->hierarchy->subtreeIds($item))->all())
            : [];

        return $tally->withKept("{$name}: no longer at the provider (kept as an archive entry because it has user data).", $item->id, $archivedFor);
    }
}
