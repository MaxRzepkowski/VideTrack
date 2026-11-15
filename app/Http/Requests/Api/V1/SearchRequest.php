<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Foundation\Http\FormRequest;

class SearchRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'q' => ['required', 'string', 'max:200'],
            'scope' => ['sometimes', 'string', 'max:50'],
        ];
    }

    public function term(): string
    {
        return trim($this->string('q')->toString());
    }

    public function scope(): string
    {
        return trim($this->string('scope')->toString());
    }
}
