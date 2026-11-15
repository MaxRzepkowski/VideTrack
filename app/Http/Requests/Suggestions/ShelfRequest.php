<?php

namespace App\Http\Requests\Suggestions;

use App\Catalog\MediaTypeRegistry;
use App\Enums\SortDirection;
use App\Suggestions\Data\ShelfQuery;
use App\Suggestions\ShelfSort;
use Illuminate\Foundation\Http\FormRequest;

/**
 * A suggestion shelf's address. Forgiving like every listing's: a value that
 * no longer makes sense falls back instead of failing the page.
 */
class ShelfRequest extends FormRequest
{
    /** A huge search term must not bloat the filtering. */
    private const MAX_QUERY = 100;

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'type' => ['nullable', 'string'],
            'origin' => ['nullable', 'string'],
            'q' => ['nullable', 'string'],
        ];
    }

    public function shelfQuery(): ShelfQuery
    {
        $type = (string) $this->string('type');
        $origin = (string) $this->string('origin');
        $q = trim((string) $this->string('q'));
        $decade = $this->integer('decade');

        return new ShelfQuery(
            sort: ShelfSort::tryFrom((string) $this->string('sort')) ?? ShelfSort::Suggested,
            dir: SortDirection::tryFrom((string) $this->string('dir')) ?? SortDirection::Asc,
            type: app(MediaTypeRegistry::class)->has($type) ? $type : null,
            origin: $origin === '' ? null : $origin,
            decade: $decade > 0 && $decade % 10 === 0 ? $decade : null,
            q: $q === '' ? null : mb_substr($q, 0, self::MAX_QUERY),
            page: max(1, $this->integer('page', 1)),
        );
    }
}
