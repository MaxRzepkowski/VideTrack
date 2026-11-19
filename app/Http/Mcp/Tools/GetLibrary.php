<?php

namespace App\Http\Mcp\Tools;

use App\Accounts\TokenAbility;
use App\Enums\LibraryTab;
use App\Http\Requests\Api\V1\PageRequest;
use App\Library\RatingScale;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Http\Request as HttpRequest;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

/**
 * GET /api/v1/library: one page of one tab, asked for by number.
 */
#[Name('get_library')]
#[Description('One page of the user\'s library: what they watched (history), rated (ratings), marked as favorite (favorites) or are in the middle of (progress), newest first, each title with the user\'s `state`.')]
#[IsReadOnly]
final class GetLibrary extends LibraryTool
{
    protected function ability(): TokenAbility
    {
        return TokenAbility::Read;
    }

    /**
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'tab' => $schema->string()->enum(array_column(LibraryTab::cases(), 'value'))->description('Which part of the library. History when left out.'),
            'type' => $schema->string()->enum(array_keys($this->types->all()))->description('Only titles of this media type.'),
            'min_rating' => $schema->integer()->min(RatingScale::MIN)->max(RatingScale::MAX)->description('Only what the user rated at least this (10: their tens).'),
            'page' => $schema->integer()->min(1)->description('The page, from 1.'),
            'per_page' => $schema->integer()->min(1)->max(PageRequest::MAX_PER_PAGE)->description('Titles per page, '.PageRequest::DEFAULT_PER_PAGE.' when left out.'),
        ];
    }

    protected function answer(Request $request, HttpRequest $http): ResponseFactory|Response
    {
        return $this->relay($this->api->call($http, 'GET', 'library', input: $request->only(['tab', 'type', 'min_rating', 'page', 'per_page'])));
    }
}
