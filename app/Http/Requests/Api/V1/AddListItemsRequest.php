<?php

namespace App\Http\Requests\Api\V1;

use App\Catalog\Data\MediaSummary;
use App\Catalog\MediaRef;
use App\Catalog\MediaTypeRegistry;
use App\Support\Payload;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** Titles for the end of a list, each by its type and ref, in the order they go on. */
class AddListItemsRequest extends FormRequest
{
    /** Titles in one call: as many as one lookup answers, and each may be fetched from its provider. */
    public const MAX_ITEMS = LookupTitlesRequest::MAX_TITLES;

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'items' => ['required', 'array', 'min:1', 'max:'.self::MAX_ITEMS],
            'items.*' => ['array'],
            'items.*.type' => ['required', 'string', Rule::in(array_keys(app(MediaTypeRegistry::class)->all()))],
            'items.*.ref' => ['required', 'string', 'max:'.MediaRef::MAX_LENGTH, MediaRef::FORMAT],
        ];
    }

    /**
     * Each position once, where it was first asked for.
     *
     * @return list<array{type: string, ref: string}>
     */
    public function positions(): array
    {
        $positions = [];

        foreach (Payload::list($this->validated('items')) as $item) {
            $type = Payload::text($item, 'type');
            $ref = Payload::text($item, 'ref');
            $positions[MediaSummary::keyOf($type, $ref)] ??= ['type' => $type, 'ref' => $ref];
        }

        return array_values($positions);
    }
}
