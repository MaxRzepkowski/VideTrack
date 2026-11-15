<?php

namespace App\Http\Requests\Api\V1;

class UpdateListsRequest extends MediaRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            ...parent::rules(),
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
