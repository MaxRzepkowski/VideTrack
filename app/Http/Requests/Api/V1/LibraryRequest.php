<?php

namespace App\Http\Requests\Api\V1;

use App\Catalog\MediaTypeRegistry;
use App\Enums\LibraryTab;
use App\Library\RatingScale;
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
            // Only what the user rated at least this: "my tens" is min_rating=10.
            'min_rating' => ['sometimes', 'integer', 'between:'.RatingScale::MIN.','.RatingScale::MAX],
            ...parent::rules(),
        ];
    }

    public function tab(): LibraryTab
    {
        return LibraryTab::tryFrom((string) $this->string('tab')) ?? LibraryTab::History;
    }

    public function minRating(): ?int
    {
        return $this->filled('min_rating') ? $this->integer('min_rating') : null;
    }

    public function mediaType(): ?string
    {
        return $this->filled('type') ? (string) $this->string('type') : null;
    }
}
