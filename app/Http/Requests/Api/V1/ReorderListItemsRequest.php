<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Foundation\Http\FormRequest;

/**
 * The drag & drop payload: every list-item id in the new manual order.
 */
class ReorderListItemsRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'item_ids' => ['present', 'array'],
            'item_ids.*' => ['integer'],
        ];
    }

    /**
     * @return list<int>
     */
    public function itemIds(): array
    {
        /** @var list<int|string> $ids the rules let only integers through */
        $ids = $this->array('item_ids');

        return array_map(fn (int|string $id): int => (int) $id, $ids);
    }
}
