<?php

namespace App\Http\Requests;

use App\Catalog\MediaTypeRegistry;
use App\Users\Statistics\StatisticsPeriod;
use Illuminate\Foundation\Http\FormRequest;

/**
 * The statistics page's address: `period` (a year or the whole history) or a `from`–`to`
 * pair of days, and `type`. Forgiving like every listing's: a value that no
 * longer makes sense falls back to the current year and every type instead
 * of failing the page.
 */
class StatisticsRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'period' => ['nullable', 'string'],
            'from' => ['nullable', 'string'],
            'to' => ['nullable', 'string'],
            'type' => ['nullable', 'string'],
        ];
    }

    public function period(int $currentYear): StatisticsPeriod
    {
        $from = $this->day('from');
        $to = $this->day('to');

        if ($from !== null && $to !== null) {
            return StatisticsPeriod::between($from, $to);
        }

        return StatisticsPeriod::fromCode((string) $this->string('period')) ?? StatisticsPeriod::ofYear($currentYear);
    }

    public function type(): ?string
    {
        $type = (string) $this->string('type');

        return in_array($type, app(MediaTypeRegistry::class)->titleCodes(), true) ? $type : null;
    }

    private function day(string $key): ?string
    {
        $value = (string) $this->string($key);

        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) === 1 && checkdate((int) substr($value, 5, 2), (int) substr($value, 8, 2), (int) substr($value, 0, 4))
            ? $value
            : null;
    }
}
