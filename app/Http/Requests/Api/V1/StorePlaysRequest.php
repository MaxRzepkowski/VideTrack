<?php

namespace App\Http\Requests\Api\V1;

use App\Library\AttemptPolicy;
use App\Library\WatchedAt;

class StorePlaysRequest extends MediaRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            ...parent::rules(),
            // 'now' | 'release' (each play stamped with the item's own
            // release date) | explicit date, optionally with time, not in
            // the future.
            'watched_at' => ['required', 'string', $this->timestampRule()],
            // Real cost of the completion in the type's progress unit
            // (games: minutes played); ignored for types without one.
            'amount' => ['nullable', 'integer', 'min:1', 'max:1000000'],
            // Keep a latest dropped attempt as its own history entry instead
            // of completing it in place (games: separate playthroughs).
            'new_attempt' => ['sometimes', 'boolean'],
            // When replacing a dropped attempt without an explicit total:
            // add its frozen hours to the sessions, or start fresh.
            'merge_hours' => ['sometimes', 'boolean'],
            // Containers only: mark the one episode the container stands at
            // instead of the whole run. Which episode that is gets resolved
            // server-side — the client knows the show it clicked, not its tree.
            'next_episode' => ['sometimes', 'boolean'],
        ];
    }

    public function nextEpisode(): bool
    {
        return $this->boolean('next_episode');
    }

    /** What the completion does with a dropped attempt it follows. */
    public function attemptPolicy(): AttemptPolicy
    {
        return match (true) {
            $this->boolean('new_attempt') => AttemptPolicy::NewAttempt,
            $this->boolean('merge_hours', true) => AttemptPolicy::ReopenDropped,
            default => AttemptPolicy::ReopenFresh,
        };
    }

    public function amount(): ?int
    {
        return $this->filled('amount') ? (int) $this->integer('amount') : null;
    }

    public function watchedAt(): WatchedAt
    {
        $value = (string) $this->string('watched_at');

        return match ($value) {
            'now' => WatchedAt::now(),
            'release' => WatchedAt::releaseDate(),
            default => WatchedAt::at($this->validatedMoment($value)),
        };
    }
}
