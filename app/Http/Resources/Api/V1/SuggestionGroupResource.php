<?php

namespace App\Http\Resources\Api\V1;

use App\Suggestions\Data\Suggestion;
use App\Suggestions\Data\SuggestionGroup;
use Illuminate\Http\Request;

/**
 * The titles of one kind of suggestion about one subject (a collection, a
 * director), or about none: each title as a card with the facts that put it
 * here, one line each.
 *
 * @mixin SuggestionGroup
 */
class SuggestionGroupResource extends ApiResource
{
    public function __construct(private readonly SuggestionGroup $group)
    {
        parent::__construct($group);
    }

    /**
     * @return array{subject: string|null, caption: string|null, finished: int|null, total: int|null, suggestions: list<array{media: array<array-key, mixed>, reason: list<string>}>}
     */
    public function toArray(Request $request): array
    {
        return [
            'subject' => $this->group->title,
            'caption' => $this->group->caption,
            'finished' => $this->group->finished,
            'total' => $this->group->total,
            'suggestions' => array_map(fn (Suggestion $suggestion): array => [
                'media' => (new MediaSummaryResource($suggestion->media))->resolve($request),
                'reason' => $suggestion->reason,
            ], $this->group->suggestions),
        ];
    }
}
