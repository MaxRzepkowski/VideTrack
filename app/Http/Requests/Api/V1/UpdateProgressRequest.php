<?php

namespace App\Http\Requests\Api\V1;

use App\Catalog\MediaTypeRegistry;
use Illuminate\Validation\Validator;

class UpdateProgressRequest extends MediaRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            ...parent::rules(),
            // One of the two: `add` appends a session, `set` overwrites the
            // counter (progress editor). Units come from the type registry.
            'add' => ['required_without:set', 'nullable', 'integer', 'min:1', 'max:1000000'],
            'set' => ['required_without:add', 'nullable', 'integer', 'min:0', 'max:1000000'],
        ];
    }

    /**
     * @return list<callable(Validator): void>
     */
    public function after(): array
    {
        return [
            function (Validator $validator) {
                if ($validator->errors()->isNotEmpty()) {
                    return;
                }

                $definition = app(MediaTypeRegistry::class)->get($this->mediaType());

                // Only flat progress types record sessions; shows derive
                // their progress from episode plays.
                if ($definition->progressUnit === null) {
                    $validator->errors()->add('type', __('app.this_title_type_has_no_recordable'));
                }
            },
        ];
    }

    public function addAmount(): ?int
    {
        return $this->filled('add') ? (int) $this->integer('add') : null;
    }

    public function setAmount(): ?int
    {
        return $this->filled('set') ? (int) $this->integer('set') : null;
    }
}
