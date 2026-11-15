<?php

namespace App\Http\Requests\Media;

use App\Catalog\MediaRef;
use App\Catalog\MediaTypeRegistry;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Manual "refresh this title" from its page. The target travels as `type` +
 * `ref` in the body; nothing else.
 */
class RefreshMediaRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'type' => ['required', 'string', Rule::in(array_keys(app(MediaTypeRegistry::class)->all()))],
            'ref' => ['required', 'string', 'max:'.MediaRef::MAX_LENGTH, MediaRef::FORMAT],
        ];
    }

    public function mediaType(): string
    {
        return (string) $this->string('type');
    }

    public function mediaRef(): string
    {
        return (string) $this->string('ref');
    }
}
