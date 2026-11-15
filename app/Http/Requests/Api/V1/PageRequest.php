<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Foundation\Http\FormRequest;

/** A plain paged listing: how many per page, nothing else. */
class PageRequest extends FormRequest
{
    /** A page of a v1 list when the client asks for no size. */
    public const DEFAULT_PER_PAGE = 25;

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ];
    }

    public function perPage(): int
    {
        return $this->integer('per_page', self::DEFAULT_PER_PAGE);
    }
}
