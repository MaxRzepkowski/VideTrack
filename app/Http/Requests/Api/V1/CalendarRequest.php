<?php

namespace App\Http\Requests\Api\V1;

use App\Accounts\UserCalendar;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;
use LogicException;

/**
 * A window of days, a month by default. A year plus a day of slack at most —
 * past that a client should ask twice.
 */
class CalendarRequest extends FormRequest
{
    private const DEFAULT_DAYS = 30;

    /** The most days between a window's first and its last day. */
    public const MAX_DAYS = 366;

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'from' => ['sometimes', 'date_format:Y-m-d'],
            'to' => ['sometimes', 'date_format:Y-m-d'],
            // The window as a count of days, the first one included ("this
            // week" is 7), for a client that does not keep the user's today.
            'days' => ['sometimes', 'integer', 'min:1', 'max:'.(self::MAX_DAYS + 1), 'prohibits:to'],
        ];
    }

    /**
     * @return list<callable(Validator): void>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                if ($validator->errors()->isNotEmpty()) {
                    return;
                }

                if ($this->to()->lt($this->from())) {
                    $validator->errors()->add('to', __('calendar.the_window_ends_before_it_starts'));
                } elseif ($this->from()->diffInDays($this->to()) > self::MAX_DAYS) {
                    $validator->errors()->add('to', __('calendar.the_window_cannot_be_longer_than', ['days' => self::MAX_DAYS]));
                }
            },
        ];
    }

    public function from(): CarbonImmutable
    {
        $from = $this->string('from')->toString();

        return $from === '' ? UserCalendar::of($this->viewer())->today() : CarbonImmutable::parse($from);
    }

    public function to(): CarbonImmutable
    {
        if ($this->filled('days')) {
            return $this->from()->addDays($this->integer('days') - 1);
        }

        $to = $this->string('to')->toString();

        return $to === '' ? $this->from()->addDays(self::DEFAULT_DAYS) : CarbonImmutable::parse($to);
    }

    private function viewer(): User
    {
        return $this->user() instanceof User ? $this->user() : throw new LogicException('The calendar is only ever asked for by a signed-in user.');
    }
}
