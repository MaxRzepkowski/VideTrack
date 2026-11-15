<?php

namespace App\Integrations\Import;

use App\Catalog\MediaTypeRegistry;
use App\Catalog\TitleStore;
use App\Library\BackfillBatch;
use App\Models\MediaItem;
use App\Models\MediaList;
use App\Models\User;
use App\Support\ExternalServiceException;
use Closure;

/**
 * One import into one account while it runs: whom it writes for and in which
 * language, the batch its entries make, what it already placed (a show is
 * resolved once for all its episodes), the lists it writes to, the statuses
 * waiting for the history, and the tally the task run reports. Made fresh for
 * every run, so nothing of one run reaches the next.
 */
final class ImportRun
{
    /** @var array<string, MediaItem|null> "type:ref" => the persisted position, null when it could not be placed */
    private array $resolved = [];

    /** @var array<int, MediaItem> id => a parent met in this run, its own parent loaded */
    private array $parents = [];

    /** @var array<string, MediaList> list key => list */
    private array $lists = [];

    /** @var array<string, true> titles placed by name rather than by id — the user is told how many */
    private array $matchedByTitle = [];

    /** @var array<string, int> */
    private array $counts = [
        'plays' => 0,
        'ratings' => 0,
        'favorites' => 0,
        'statuses' => 0,
        'drops' => 0,
        'list_items' => 0,
        'cycles' => 0,
        'follows' => 0,
        'skipped' => 0,
    ];

    /** @var list<string> */
    private array $errors = [];

    /** @var list<Closure(): void> */
    private array $statuses = [];

    public function __construct(
        public readonly User $user,
        public readonly string $locale,
        public readonly BackfillBatch $batch,
        private readonly MediaTypeRegistry $types,
        private readonly TitleStore $persist,
    ) {}

    /**
     * Provider coordinates as a persisted position. Null — and the entry
     * counted as skipped — when the type is not registered, the provider does
     * not serve it here, or the provider no longer knows the reference.
     */
    public function resolve(string $type, string $provider, string $ref, string $label): ?MediaItem
    {
        $key = "{$type}:{$ref}";

        if (array_key_exists($key, $this->resolved)) {
            if ($this->resolved[$key] === null) {
                $this->counts['skipped']++;
            }

            return $this->resolved[$key];
        }

        if (! $this->types->has($type) || $this->types->get($type)->provider !== $provider) {
            $this->skip('import.skip_no_provider', ['label' => $label, 'provider' => $provider, 'type' => $type]);

            return $this->resolved[$key] = null;
        }

        try {
            return $this->resolved[$key] = $this->withParents($this->persist->persist($type, $ref, $this->locale));
        } catch (ExternalServiceException $e) {
            $this->unavailable($label, $e);

            return $this->resolved[$key] = null;
        }
    }

    /** An entry left out because the service behind it would not answer. */
    public function unavailable(string $label, ExternalServiceException $e): void
    {
        // The service's refusal is worded by its client, without the request.
        $this->skip('import.skip_unavailable', ['label' => $label, 'reason' => $e->wording($this->locale)]);
    }

    /**
     * The item with its chain of parents loaded, each parent read once per
     * run: every episode of a show asks for the same season and show.
     */
    private function withParents(MediaItem $item): MediaItem
    {
        $node = $item;

        while ($node->parent_id !== null && ! $node->relationLoaded('parent')) {
            $parent = $this->parents[$node->parent_id] ??= $node->parent()->firstOrFail();
            $node->setRelation('parent', $parent);
            $node = $parent;
        }

        return $item;
    }

    /**
     * The list a row writes to, made by $make the first time the run meets it.
     *
     * @param  Closure(): MediaList  $make
     */
    public function list(string $key, Closure $make): MediaList
    {
        return $this->lists[$key] ??= $make();
    }

    /**
     * A status the file gives a title — on hold, dropped — written once the
     * run's history is in. It is what the title is now: a viewing a later row
     * brings must not close it, nor refuse it, so the order of the rows does
     * not decide.
     *
     * @param  Closure(): void  $write
     */
    public function afterHistory(Closure $write): void
    {
        $this->statuses[] = $write;
    }

    /**
     * The statuses held back by {@see afterHistory()}, each given once.
     *
     * @return list<Closure(): void>
     */
    public function takeStatuses(): array
    {
        [$statuses, $this->statuses] = [$this->statuses, []];

        return $statuses;
    }

    /**
     * Every position the run placed, whether a row wrote to it or found it
     * written already.
     *
     * @return list<int>
     */
    public function placedIds(): array
    {
        return array_values(array_map(fn (MediaItem $item): int => $item->id, array_filter($this->resolved)));
    }

    public function matchedByTitle(string $key): void
    {
        $this->matchedByTitle[$key] = true;
    }

    /** One more of a kind of entry written: plays, ratings, favorites, statuses, drops, list_items, cycles, follows. */
    public function wrote(string $kind, bool $written = true): void
    {
        if ($written) {
            $this->counts[$kind]++;
        }
    }

    /**
     * An entry left out, with why, in the user's language. The same
     * unresolvable show repeats for every episode row; one message per cause
     * keeps the run readable.
     *
     * @param  array<string, string|int>  $replace
     */
    public function skip(string $key, array $replace = []): void
    {
        $this->counts['skipped']++;
        $this->note(__($key, $replace, $this->locale));
    }

    public function summary(): string
    {
        return __('import.run_summary', $this->counts, $this->locale);
    }

    /** The report of a run that went through all its rows; $total = 0 when the source did not know it upfront. */
    public function report(int $total, int $processed): string
    {
        $summary = trans_choice('import.run_processed', $total > 0 ? $total : $processed, [], $this->locale).' '.$this->summary();

        if ($this->matchedByTitle !== []) {
            $summary .= ' '.trans_choice('import.run_matched_by_name', count($this->matchedByTitle), [], $this->locale);
        }

        return $summary;
    }

    public function progress(int $processed, int $total): string
    {
        return $total > 0
            ? __('import.run_processing_of', ['processed' => $processed, 'total' => $total, 'summary' => $this->summary()], $this->locale)
            : __('import.run_processing', ['processed' => $processed, 'summary' => $this->summary()], $this->locale);
    }

    /**
     * @return list<string>
     */
    public function errors(): array
    {
        return $this->errors;
    }

    private function note(string $error): void
    {
        if (! in_array($error, $this->errors, true)) {
            $this->errors[] = $error;
        }
    }
}
