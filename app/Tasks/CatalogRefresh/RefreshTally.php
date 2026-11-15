<?php

namespace App\Tasks\CatalogRefresh;

/**
 * What one run of the background refresh has done so far, carried from one
 * batch to the next: the counts its summary reports, the notes and errors it
 * lists, and the providers it has stopped asking or waits for.
 */
final readonly class RefreshTally
{
    /**
     * @param  array<string, int>  $planned  reason => roots waiting when the run began
     * @param  array<string, int>  $refreshed  reason => roots refreshed
     * @param  list<string>  $removed  roots deleted, gone from the provider with no user data
     * @param  list<string>  $kept  roots gone from the provider, kept for their user data
     * @param  list<string>  $heavy  roots whose refresh took the worker's memory past the threshold
     * @param  array<int, list<int>>  $archived  user id => their roots that became archive entries
     * @param  list<string>  $errors
     * @param  array<string, int>  $failuresInARow  provider => failures since its last answer
     * @param  list<string>  $stopped  providers not asked again this run
     * @param  array<string, int>  $busyUntil  provider => unix time it may be asked again
     * @param  list<string>  $measuredTypes  types whose incomplete list of changes answered today
     * @param  list<int>  $named  roots the lists of changes named today
     * @param  array<string, int>  $changed  measured type => roots whose schedule the refresh changed
     * @param  array<string, int>  $changedNamed  measured type => those of them the list named
     * @param  list<string>  $observations  what the measuring noticed, for the run's details
     */
    public function __construct(
        public array $planned = [],
        public array $refreshed = [],
        public int $processed = 0,
        public int $notified = 0,
        public array $removed = [],
        public array $kept = [],
        public array $heavy = [],
        public array $archived = [],
        public array $errors = [],
        public array $failuresInARow = [],
        public array $stopped = [],
        public array $busyUntil = [],
        public array $measuredTypes = [],
        public array $named = [],
        public array $changed = [],
        public array $changedNamed = [],
        public array $observations = [],
    ) {}

    public function total(): int
    {
        return array_sum($this->planned);
    }

    public function refreshedCount(): int
    {
        return array_sum($this->refreshed);
    }

    /**
     * Providers not to ask at $now: stopped, or still waited for.
     *
     * @return list<string>
     */
    public function skippedAt(int $now): array
    {
        $waiting = array_keys(array_filter($this->busyUntil, fn (int $until): bool => $until > $now));

        return array_values(array_unique([...$this->stopped, ...$waiting]));
    }

    /** The earliest moment a provider waited for may be asked again; null when none is. */
    public function nextWakeAfter(int $now): ?int
    {
        $waits = array_filter($this->busyUntil, fn (int $until): bool => $until > $now);

        return $waits === [] ? null : min($waits);
    }

    public function withRefreshed(RefreshReason $reason, string $provider, int $notified): self
    {
        return $this->with(
            refreshed: [...$this->refreshed, $reason->value => ($this->refreshed[$reason->value] ?? 0) + 1],
            processed: $this->processed + 1,
            notified: $this->notified + $notified,
            failuresInARow: [...$this->failuresInARow, $provider => 0],
        );
    }

    public function withRemoved(string $line): self
    {
        return $this->with(removed: [...$this->removed, $line], processed: $this->processed + 1);
    }

    /**
     * @param  list<int>  $archivedFor  users whose history made the root an archive entry
     */
    public function withKept(string $line, int $rootId, array $archivedFor): self
    {
        $archived = $this->archived;

        foreach ($archivedFor as $userId) {
            $archived[$userId] = [...($archived[$userId] ?? []), $rootId];
        }

        return $this->with(kept: [...$this->kept, $line], archived: $archived, processed: $this->processed + 1);
    }

    /** The provider failed for one root; after $stopAfter failures in a row it is not asked again this run. */
    public function withFailure(string $provider, string $error, int $stopAfter): self
    {
        $failures = ($this->failuresInARow[$provider] ?? 0) + 1;

        return $this->with(
            errors: [...$this->errors, $error],
            processed: $this->processed + 1,
            failuresInARow: [...$this->failuresInARow, $provider => $failures],
            stopped: $failures >= $stopAfter ? $this->stopping($provider) : $this->stopped,
        );
    }

    /** The provider refused us: asking again this run would be refused the same. */
    public function withRefusal(string $provider, string $error): self
    {
        return $this->with(errors: [...$this->errors, $error], stopped: $this->stopping($provider));
    }

    public function withBusy(string $provider, int $until): self
    {
        return $this->with(busyUntil: [...$this->busyUntil, $provider => $until]);
    }

    public function withHeavy(?string $note): self
    {
        return $note === null ? $this : $this->with(heavy: [...$this->heavy, $note]);
    }

    /** A measured root was refreshed; $changed whether its schedule moved. */
    public function withMeasured(string $type, int $rootId, bool $changed, string $name): self
    {
        if (! $changed) {
            return $this;
        }

        $named = in_array($rootId, $this->named, true);

        return $this->with(
            changed: [...$this->changed, $type => ($this->changed[$type] ?? 0) + 1],
            changedNamed: $named ? [...$this->changedNamed, $type => ($this->changedNamed[$type] ?? 0) + 1] : $this->changedNamed,
            observations: $named ? $this->observations : [...$this->observations, "{$name}: changed, and the list of changes did not name it."],
        );
    }

    public function withObservation(?string $note): self
    {
        return $note === null ? $this : $this->with(observations: [...$this->observations, $note]);
    }

    public function withNotified(int $sent): self
    {
        return $this->with(notified: $this->notified + $sent);
    }

    /** @return list<string> */
    private function stopping(string $provider): array
    {
        return array_values(array_unique([...$this->stopped, $provider]));
    }

    /**
     * This tally with the given counts replaced.
     *
     * @param  array<string, int>|null  $refreshed
     * @param  list<string>|null  $removed
     * @param  list<string>|null  $kept
     * @param  list<string>|null  $heavy
     * @param  array<int, list<int>>|null  $archived
     * @param  list<string>|null  $errors
     * @param  array<string, int>|null  $failuresInARow
     * @param  list<string>|null  $stopped
     * @param  array<string, int>|null  $busyUntil
     * @param  array<string, int>|null  $changed
     * @param  array<string, int>|null  $changedNamed
     * @param  list<string>|null  $observations
     */
    private function with(
        ?array $refreshed = null,
        ?int $processed = null,
        ?int $notified = null,
        ?array $removed = null,
        ?array $kept = null,
        ?array $heavy = null,
        ?array $archived = null,
        ?array $errors = null,
        ?array $failuresInARow = null,
        ?array $stopped = null,
        ?array $busyUntil = null,
        ?array $changed = null,
        ?array $changedNamed = null,
        ?array $observations = null,
    ): self {
        return new self(
            planned: $this->planned,
            refreshed: $refreshed ?? $this->refreshed,
            processed: $processed ?? $this->processed,
            notified: $notified ?? $this->notified,
            removed: $removed ?? $this->removed,
            kept: $kept ?? $this->kept,
            heavy: $heavy ?? $this->heavy,
            archived: $archived ?? $this->archived,
            errors: $errors ?? $this->errors,
            failuresInARow: $failuresInARow ?? $this->failuresInARow,
            stopped: $stopped ?? $this->stopped,
            busyUntil: $busyUntil ?? $this->busyUntil,
            measuredTypes: $this->measuredTypes,
            named: $this->named,
            changed: $changed ?? $this->changed,
            changedNamed: $changedNamed ?? $this->changedNamed,
            observations: $observations ?? $this->observations,
        );
    }
}
