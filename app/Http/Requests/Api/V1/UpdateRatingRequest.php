<?php

namespace App\Http\Requests\Api\V1;

use App\Library\RatingScale;

class UpdateRatingRequest extends MediaRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            ...parent::rules(),
            'rating' => ['required', 'integer', 'between:'.RatingScale::MIN.','.RatingScale::MAX],
        ];
    }
}
