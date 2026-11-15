<?php

namespace App\Tasks\CatalogRefresh;

use App\Catalog\Contracts\SupportsChangeFeed;
use App\Catalog\MediaTypeRegistry;
use App\Catalog\ProviderRegistry;
use App\Models\MediaItem;
use App\Support\ExternalServiceException;
use App\Support\Numbers;

/**
 * Asks every provider with a list of changes, type by type, which of our
 * roots changed since the list was last read. Reads only: the marks and the
 * new place in each list are written together by the queue.
 *
 * A list read for the first time, or one that cannot tell for its window,
 * answers "unknown": that type is asked about as if it had no list today,
 * and its place still moves on, so tomorrow's window is a day again. A list
 * that failed keeps its place and is asked about the same window tomorrow.
 */
final readonly class ChangeFeeds
{
    public function __construct(
        private ProviderRegistry $providers,
        private MediaTypeRegistry $types,
        private RefreshQueue $queue,
    ) {}

    public function read(): ChangeFeedReading
    {
        $changed = [];
        $covered = [];
        $complete = [];
        $answered = [];
        $cursors = [];
        $errors = [];

        foreach ($this->providers->allSupporting(SupportsChangeFeed::class) as $code => $provider) {
            /** @var SupportsChangeFeed $provider */
            $served = array_intersect($provider->changeFeedTypes(), $this->types->rootCodes());
            $isComplete = array_intersect($provider->completeChangeFeedTypes(), $served);
            $complete = [...$complete, ...$isComplete];

            foreach ($served as $type) {
                $asked = now()->toImmutable();
                $since = $this->queue->checkedUntil($code, $type);
                $roots = [];

                foreach (MediaItem::query()->whereNull('parent_id')->where('provider', $code)->where('type', $type)->pluck('id', 'provider_ref') as $ref => $id) {
                    $roots[(string) $ref] = Numbers::int($id) ?? 0;
                }

                try {
                    $report = $since === null ? null : $provider->changedAmong($type, array_map('strval', array_keys($roots)), $since);
                } catch (ExternalServiceException $e) {
                    $errors[] = "The list of changed {$type} titles at {$code} could not be read: {$e->wording()}";

                    continue;
                }

                $cursors[] = ['provider' => $code, 'type' => $type, 'checked_until' => $asked];

                if ($report === null || ! $report->isKnown()) {
                    continue;
                }

                $answered[] = $type;

                foreach ($report->refs ?? [] as $ref) {
                    if (isset($roots[$ref])) {
                        $changed[] = $roots[$ref];
                    }
                }

                if (in_array($type, $isComplete, true)) {
                    $covered[] = $type;
                }
            }
        }

        return new ChangeFeedReading(
            changedRootIds: array_values(array_unique($changed)),
            coveredTypes: $covered,
            completeTypes: array_values(array_unique($complete)),
            answeredTypes: $answered,
            cursors: $cursors,
            errors: $errors,
        );
    }
}
