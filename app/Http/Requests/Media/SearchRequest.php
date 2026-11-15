<?php

namespace App\Http\Requests\Media;

use Illuminate\Foundation\Http\FormRequest;

/**
 * The search page's address: what was typed and where to look. Both may be
 * missing — an empty page waits for typing, an unknown scope falls back to
 * the first one offered. A pasted wall of text is cut, not refused: live
 * search reloads on every keystroke and has no form to show an error on.
 */
class SearchRequest extends FormRequest
{
    /** The longest term any search reads; the palette cuts at the same length. */
    public const MAX_TERM = 200;

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'q' => ['nullable', 'string'],
            'scope' => ['nullable', 'string', 'max:50'],
        ];
    }

    public function term(): string
    {
        return mb_substr(trim((string) $this->string('q')), 0, self::MAX_TERM);
    }

    public function scope(): string
    {
        return trim((string) $this->string('scope'));
    }
}
