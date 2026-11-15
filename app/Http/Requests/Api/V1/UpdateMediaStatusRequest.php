<?php

namespace App\Http\Requests\Api\V1;

use App\Catalog\MediaTypeRegistry;
use App\Enums\MediaUserStatus;
use App\Enums\PositionStatus;
use App\Library\LoggedSessions;
use App\Library\WatchedAt;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * One endpoint, two attempt-model semantics: `dropped` CLOSES the current
 * attempt (a dated play entry — allowed on roots with a user status and on
 * nested positions, where it reads as "skip"), while `on_hold` pauses the
 * OPEN attempt (state row, roots only). Null clears both: the open state
 * and a current drop. "Playing" (in_progress) is automatic — sessions set
 * it (LogProgress); it is never written here.
 */
class UpdateMediaStatusRequest extends MediaRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            ...parent::rules(),
            'status' => ['nullable', Rule::in([PositionStatus::Dropped->value, PositionStatus::OnHold->value])],
            // Hours sunk into the dropped attempt (progress types) — the
            // drop freezes them into the play entry.
            'amount' => ['nullable', 'integer', 'min:1', 'max:1000000'],
            // The drop is a dated history entry — same 'now' | 'release' |
            // explicit date convention as logging a play.
            'dropped_at' => ['nullable', 'string', $this->timestampRule()],
            // Clearing the status resumes the attempt by default; this asks
            // to throw it away instead, logged sessions included.
            'discard_progress' => ['sometimes', 'boolean'],
        ];
    }

    /** Only meaningful together with a null status. */
    public function loggedSessions(): LoggedSessions
    {
        return $this->boolean('discard_progress') ? LoggedSessions::Discard : LoggedSessions::Keep;
    }

    public function amount(): ?int
    {
        return $this->filled('amount') ? (int) $this->integer('amount') : null;
    }

    /**
     * When the drop happened: absent, null or 'now' stamp it now; 'release'
     * stamps the item's release date (or now, for an item without one,
     * resolved by the action, which knows the item); an ISO moment is taken
     * as given.
     */
    public function droppedAt(): WatchedAt
    {
        $value = (string) $this->string('dropped_at');

        return match ($value) {
            '', 'now' => WatchedAt::now(),
            'release' => WatchedAt::releaseDateOrNow(),
            default => WatchedAt::at($this->validatedMoment($value)),
        };
    }

    /**
     * @return list<callable(Validator): void>
     */
    public function after(): array
    {
        return [
            function (Validator $validator) {
                if ($validator->errors()->isNotEmpty()) {
                    return;
                }

                $definition = app(MediaTypeRegistry::class)->get($this->mediaType());

                // Dropping also covers nested positions (skip an episode or
                // a season); open-attempt states live on the roots that
                // declare them (shows, movies, games).
                if ($this->wantsDrop()) {
                    if ($definition->isRoot() && ! $definition->userStatus) {
                        $validator->errors()->add('type', __('app.this_title_type_has_no_viewing'));
                    }

                    return;
                }

                if ($this->input('status') !== null && ! $definition->takesOpenStatus()) {
                    $validator->errors()->add('type', __('app.this_title_type_has_no_viewing'));
                }
            },
        ];
    }

    public function wantsDrop(): bool
    {
        return $this->input('status') === PositionStatus::Dropped->value;
    }

    public function status(): ?MediaUserStatus
    {
        return $this->wantsDrop() ? null : $this->enum('status', MediaUserStatus::class);
    }
}
