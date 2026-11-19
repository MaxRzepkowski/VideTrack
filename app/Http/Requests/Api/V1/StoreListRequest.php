<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Foundation\Http\FormRequest;

/** A new, empty list: its title is all it starts with. */
class StoreListRequest extends FormRequest
{
    public const MAX_TITLE_LENGTH = 100;

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:'.self::MAX_TITLE_LENGTH],
        ];
    }

    public function title(): string
    {
        return $this->string('title')->toString();
    }
}
