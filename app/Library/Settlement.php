<?php

namespace App\Library;

/**
 * How far one run has settled a container's leaves — the attempt model's one
 * rule for "done", whoever asks it (the state on every card, dropping, marking
 * a container watched, the history of past runs).
 *
 * A leaf is settled by any entry of its own in the run, or by its season's
 * drop: the gap moves past it, though it was not watched. A container is
 * completed when every aired leaf is settled and at least one aired leaf was
 * really watched — a skipped season settles gaps, but cannot finish a run
 * nobody watched.
 */
final readonly class Settlement
{
    /**
     * @param  array<int, int>  $completions  leaf id => its completed entries in the run; a leaf with any entry is present
     * @param  array<int, mixed>  $drops  container id => its drop in the run
     */
    public function __construct(
        private array $completions,
        private array $drops,
    ) {}

    public function settles(int $leafId, ?int $parentId): bool
    {
        return isset($this->completions[$leafId]) || ($parentId !== null && isset($this->drops[$parentId]));
    }

    public function watched(int $leafId): bool
    {
        return ($this->completions[$leafId] ?? 0) > 0;
    }

    /**
     * @param  array<int, int|null>  $aired  aired leaf id => its parent's id
     */
    public function completes(array $aired): bool
    {
        if ($aired === []) {
            return false;
        }

        $watched = false;

        foreach ($aired as $leafId => $parentId) {
            if (! $this->settles($leafId, $parentId)) {
                return false;
            }

            $watched = $watched || $this->watched($leafId);
        }

        return $watched;
    }

    /** The same run, but this container's own drop no longer settles its leaves. */
    public function withoutDropOf(int $containerId): self
    {
        $drops = $this->drops;
        unset($drops[$containerId]);

        return new self($this->completions, $drops);
    }
}
