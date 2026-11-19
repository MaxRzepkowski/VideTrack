<?php

namespace App\Http\Requests\Api\V1;

use App\Catalog\MediaTypeRegistry;
use App\Enums\CountdownSort;
use App\Enums\SortDirection;
use App\Tracking\Data\CountdownFilters;
use App\Tracking\Surfaces\CountdownSet;
use Illuminate\Validation\Rule;

/**
 * The countdowns, narrowed: a kind (a root type, or `event`), a search,
 * an order, a page.
 */
class CountdownsRequest extends PageRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'type' => ['sometimes', Rule::in(CountdownSet::kindCodes(app(MediaTypeRegistry::class)))],
            'q' => ['sometimes', 'string', 'max:'.CountdownSet::MAX_QUERY],
            'sort' => ['sometimes', Rule::enum(CountdownSort::class)],
            'dir' => ['sometimes', Rule::enum(SortDirection::class)],
            'page' => ['sometimes', 'integer', 'min:1'],
            ...parent::rules(),
        ];
    }

    public function filters(): CountdownFilters
    {
        return new CountdownFilters(
            sort: $this->enum('sort', CountdownSort::class) ?? CountdownSort::Premiere,
            dir: $this->enum('dir', SortDirection::class) ?? SortDirection::Asc,
            type: $this->filled('type') ? $this->string('type')->toString() : null,
            q: $this->filled('q') ? trim($this->string('q')->toString()) : null,
        );
    }

    public function page(): int
    {
        return $this->integer('page', 1);
    }
}
