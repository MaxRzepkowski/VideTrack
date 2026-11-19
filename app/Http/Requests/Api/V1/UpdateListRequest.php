<?php

namespace App\Http\Requests\Api\V1;

use App\Models\MediaList;
use Illuminate\Auth\Access\Response;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use LogicException;

class UpdateListRequest extends FormRequest
{
    public const MAX_DESCRIPTION_LENGTH = 1000;

    /** Before the rules: they depend on what list it is, which a stranger must not learn. */
    public function authorize(): Response
    {
        return Gate::inspect('update', $this->list());
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            // The watchlist has no title to send: it is named in the
            // reader's language, and only its visibility changes.
            'title' => [$this->list()->isSystem() ? 'nullable' : 'required', 'string', 'max:'.StoreListRequest::MAX_TITLE_LENGTH],
            'description' => ['nullable', 'string', 'max:'.self::MAX_DESCRIPTION_LENGTH],
            'is_public' => ['required', 'boolean'],
        ];
    }

    private function list(): MediaList
    {
        $list = $this->route('list');

        return $list instanceof MediaList ? $list : throw new LogicException('A list update is routed to its list.');
    }

    public function title(): string
    {
        return $this->string('title')->toString();
    }

    public function description(): ?string
    {
        return $this->filled('description') ? $this->string('description')->toString() : null;
    }

    public function isPublic(): bool
    {
        return $this->boolean('is_public');
    }
}
