<?php

namespace App\Http\Requests;

use App\Enums\CountdownSort;
use App\Enums\SortDirection;
use App\Tracking\Data\CountdownFilters;
use App\Tracking\Surfaces\CountdownSet;
use Illuminate\Foundation\Http\FormRequest;

/**
 * The countdowns page's address. Forgiving like every listing's: a value that
 * no longer makes sense falls back instead of failing the page.
 */
class CountdownsRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'q' => ['nullable', 'string'],
            'type' => ['nullable', 'string'],
        ];
    }

    public function countdownFilters(): CountdownFilters
    {
        $type = (string) $this->string('type');
        $q = trim((string) $this->string('q'));

        return new CountdownFilters(
            sort: CountdownSort::tryFrom((string) $this->string('sort')) ?? CountdownSort::Premiere,
            dir: SortDirection::tryFrom((string) $this->string('dir')) ?? SortDirection::Asc,
            type: $type === '' ? null : $type,
            q: $q === '' ? null : mb_substr($q, 0, CountdownSet::MAX_QUERY),
        );
    }

    public function page(): int
    {
        return max(1, $this->integer('page', 1));
    }
}
