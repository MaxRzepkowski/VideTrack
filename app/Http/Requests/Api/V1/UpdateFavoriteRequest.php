<?php

namespace App\Http\Requests\Api\V1;

class UpdateFavoriteRequest extends MediaRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            ...parent::rules(),
            'favorite' => ['required', 'boolean'],
        ];
    }
}
