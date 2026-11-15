<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Foundation\Http\FormRequest;

/**
 * The drag & drop payload of the lists index: every list id in the new
 * manual order.
 */
class ReorderListsRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'list_ids' => ['present', 'array'],
            'list_ids.*' => ['integer'],
        ];
    }

    /**
     * @return list<int>
     */
    public function listIds(): array
    {
        /** @var list<int|string> $ids the rules let only integers through */
        $ids = $this->array('list_ids');

        return array_map(fn (int|string $id): int => (int) $id, $ids);
    }
}
