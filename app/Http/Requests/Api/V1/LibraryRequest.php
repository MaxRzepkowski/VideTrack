<?php

namespace App\Http\Requests\Api\V1;

use App\Catalog\MediaTypeRegistry;
use App\Enums\LibraryTab;
use Illuminate\Validation\Rule;

class LibraryRequest extends PageRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'tab' => ['sometimes', Rule::enum(LibraryTab::class)],
            'type' => ['sometimes', Rule::in(array_keys(app(MediaTypeRegistry::class)->all()))],
            ...parent::rules(),
        ];
    }

    public function tab(): LibraryTab
    {
        return LibraryTab::tryFrom((string) $this->string('tab')) ?? LibraryTab::History;
    }

    public function mediaType(): ?string
    {
        return $this->filled('type') ? (string) $this->string('type') : null;
    }
}
