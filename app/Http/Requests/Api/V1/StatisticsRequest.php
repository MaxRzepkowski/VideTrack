<?php

namespace App\Http\Requests\Api\V1;

use App\Accounts\UserCalendar;
use App\Catalog\MediaTypeRegistry;
use App\Models\User;
use App\Users\Statistics\StatisticsPeriod;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use LogicException;

/**
 * The period statistics are counted over, the user's current year by default:
 * a year, the whole history (`all`) or a `from`–`to` pair of days; and one
 * root type, or every type.
 */
class StatisticsRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'period' => ['sometimes', 'string', 'regex:/'.StatisticsPeriod::CODE_PATTERN.'/', 'prohibits:from,to'],
            'from' => ['required_with:to', 'date_format:Y-m-d'],
            'to' => ['required_with:from', 'date_format:Y-m-d', 'after_or_equal:from'],
            'type' => ['sometimes', Rule::in(app(MediaTypeRegistry::class)->titleCodes())],
        ];
    }

    public function period(): StatisticsPeriod
    {
        if ($this->filled('from')) {
            return StatisticsPeriod::between($this->string('from')->toString(), $this->string('to')->toString());
        }

        return StatisticsPeriod::fromCode($this->string('period')->toString())
            ?? StatisticsPeriod::ofYear(UserCalendar::of($this->viewer())->today()->year);
    }

    public function type(): ?string
    {
        return $this->filled('type') ? $this->string('type')->toString() : null;
    }

    private function viewer(): User
    {
        return $this->user() instanceof User ? $this->user() : throw new LogicException('Statistics are only ever asked for by a signed-in user.');
    }
}
