<?php

namespace App\Http\Requests\Media;

use App\Catalog\Data\DiscoverQuery;
use App\Catalog\Discovery;
use Illuminate\Foundation\Http\FormRequest;

/**
 * The discover page's address. Every part is forgiving: these are links that
 * age (a page past the end, a dropped genre), and an aged link opens the
 * nearest sensible page rather than an error.
 */
class DiscoverRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'type' => ['nullable', 'string'],
            'genre' => ['nullable', 'string'],
        ];
    }

    public function discoverQuery(): DiscoverQuery
    {
        $type = trim((string) $this->string('type'));
        $genre = trim((string) $this->string('genre'));
        $filters = $this->input('f');

        return new DiscoverQuery(
            type: $type === '' ? (app(Discovery::class)->defaultType() ?? '') : $type,
            genre: $genre === '' ? null : $genre,
            filters: is_array($filters) ? $filters : null,
            page: max(1, $this->integer('page', 1)),
        );
    }
}
