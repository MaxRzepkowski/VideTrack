<?php

namespace App\Http\Resources\Api\V1;

use App\Suggestions\Data\SuggestionShelf;
use Illuminate\Http\Request;

/**
 * A kind of suggestion that has something for the user: what it is and how
 * many titles wait behind it. Its titles are `suggestions/{key}`.
 *
 * @mixin SuggestionShelf
 */
class SuggestionShelfResource extends ApiResource
{
    public function __construct(private readonly SuggestionShelf $shelf)
    {
        parent::__construct($shelf);
    }

    /**
     * @return array{key: string, title: string, description: string, total: int}
     */
    public function toArray(Request $request): array
    {
        return [
            'key' => $this->shelf->key,
            'title' => $this->shelf->title,
            'description' => $this->shelf->description,
            'total' => $this->shelf->total,
        ];
    }
}
