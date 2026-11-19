<?php

namespace App\Http\Requests\Api\V1;

use App\Catalog\MediaKind;
use App\Catalog\MediaTypeRegistry;
use App\Integrations\Import\Data\TitleQuery;
use App\Support\Payload;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** Titles by name, each with its media type and what else is known of it. */
class LookupTitlesRequest extends FormRequest
{
    /** Titles in one call: each is a search at its catalogue. */
    public const MAX_TITLES = 25;

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'titles' => ['required', 'array', 'min:1', 'max:'.self::MAX_TITLES],
            'titles.*.title' => ['required', 'string', 'max:'.SearchRequest::MAX_QUERY_LENGTH],
            'titles.*.type' => ['required', Rule::in(app(MediaTypeRegistry::class)->searchableCodes())],
            'titles.*.year' => ['nullable', 'integer'],
            'titles.*.creator' => ['nullable', 'string', 'max:'.SearchRequest::MAX_QUERY_LENGTH],
        ];
    }

    /**
     * Each title asked for, named exactly: a lookup by name that settled for
     * the catalogue's best guess would tell the user they saw a film they
     * did not.
     *
     * @return list<array{kind: MediaKind, query: TitleQuery}>
     */
    public function lookups(): array
    {
        return array_map(fn (array $title): array => [
            'kind' => MediaKind::from(Payload::text($title, 'type')),
            'query' => new TitleQuery(
                title: Payload::text($title, 'title'),
                year: Payload::int($title, 'year'),
                creator: Payload::string($title, 'creator'),
                exactTitle: true,
            ),
        ], Payload::list($this->validated('titles')));
    }
}
