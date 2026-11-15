<?php

namespace App\Http\Requests\Api\V1;

use App\Catalog\MediaRef;
use App\Catalog\MediaTypeRegistry;
use App\Catalog\TitleStore;
use App\Models\MediaItem;
use App\Support\StoredTime;
use Carbon\CarbonImmutable;
use Carbon\Exceptions\InvalidFormatException;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use LogicException;

/**
 * A call addressed to one position: `media/{type}/{ref}`. The route holds the
 * ref's shape; this adds its length and turns a type the instance does not
 * serve (unknown, or a module whose keys are not configured) into a resource
 * that does not exist — 404, not an error on a field the client never sent.
 *
 * Used as is where the target is all there is (reading, unmarking, clearing a
 * rating); the writes that take fields extend it. Timestamp fields share the
 * 'now' | 'release' | explicit-date convention of logging a play.
 */
class MediaRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        abort_unless(app(MediaTypeRegistry::class)->has($this->mediaType()), 404);
    }

    /**
     * @return array<array-key, mixed>
     */
    public function validationData(): array
    {
        return [...$this->all(), 'ref' => $this->mediaRef()];
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'ref' => ['max:'.MediaRef::MAX_LENGTH],
        ];
    }

    public function mediaType(): string
    {
        return (string) $this->route('type');
    }

    public function mediaRef(): string
    {
        return (string) $this->route('ref');
    }

    /**
     * The stored row of the addressed position. A position nobody touched has
     * none yet — reads answer "nothing", writes that need one refuse.
     */
    public function storedItem(): ?MediaItem
    {
        return app(TitleStore::class)->stored($this->mediaType(), $this->mediaRef());
    }

    /**
     * Validation closure for a 'now' | 'release' | date field.
     */
    protected function timestampRule(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail) {
            if (in_array($value, ['now', 'release'], true)) {
                return;
            }

            $date = is_string($value) ? $this->parseDate($value) : null;

            if ($date === null || $date->isFuture()) {
                $fail(__('app.pick_a_valid_date_no_later'));
            }
        };
    }

    /** A timestamp the rules let through: it parses, or the rules and the parser disagree. */
    protected function validatedMoment(string $value): CarbonImmutable
    {
        return $this->parseDate($value) ?? throw new LogicException("A validated timestamp [{$value}] did not parse.");
    }

    protected function parseDate(string $value): ?CarbonImmutable
    {
        // The modal converts its datetime-local input to UTC before sending
        // (Date.toISOString), so the entered wall time lands in the database
        // as a universal instant.
        try {
            $instant = CarbonImmutable::createFromFormat('Y-m-d\TH:i:s.v\Z', $value, 'UTC');

            if ($instant !== null) {
                return StoredTime::of($instant)->startOfMinute();
            }
        } catch (InvalidFormatException) {
            // fall through to the naive formats below
        }

        foreach (['Y-m-d\TH:i' => 'startOfMinute', 'Y-m-d' => 'startOfDay'] as $format => $normalize) {
            try {
                $date = CarbonImmutable::createFromFormat($format, $value);
            } catch (InvalidFormatException) {
                continue;
            }

            if ($date !== null) {
                return $date->{$normalize}();
            }
        }

        return null;
    }
}
