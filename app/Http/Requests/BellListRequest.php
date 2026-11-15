<?php

namespace App\Http\Requests;

use App\Tracking\BellEntryKind;
use App\Tracking\Data\BellListFilters;
use Illuminate\Foundation\Http\FormRequest;

/**
 * The bell list's address. Forgiving like every listing's: a value that no
 * longer makes sense falls back instead of failing the page.
 */
class BellListRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'kind' => ['nullable', 'string'],
            'type' => ['nullable', 'string'],
        ];
    }

    public function filters(): BellListFilters
    {
        $type = (string) $this->string('type');

        return new BellListFilters(
            kind: BellEntryKind::tryFrom((string) $this->string('kind')),
            type: $type === '' ? null : $type,
        );
    }

    public function page(): int
    {
        return max(1, $this->integer('page', 1));
    }
}
