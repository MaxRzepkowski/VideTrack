<?php

namespace App\Http\Mcp\Tools;

use App\Accounts\TokenAbility;
use App\Http\Requests\Api\V1\LookupTitlesRequest;
use App\Http\Requests\Api\V1\SearchRequest;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Http\Request as HttpRequest;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

/** POST /api/v1/lookup. */
#[Name('check_titles')]
#[Description('Look up many titles by name at once and see the user\'s `state` for each: whether they watched it, how they rated it, whether it is on a list. A title is matched by its name (or another name it goes by) and its year, a year either way; `media` is missing when nothing matches, `unavailable` when its catalogue could not be asked.')]
#[IsReadOnly]
final class CheckTitles extends LibraryTool
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
            'titles' => $schema->array()->min(1)->max(LookupTitlesRequest::MAX_TITLES)->items($schema->object([
                'title' => $schema->string()->max(SearchRequest::MAX_QUERY_LENGTH)->required(),
                'type' => $schema->string()->enum($this->types->searchableCodes())->required(),
                'year' => $schema->integer(),
                'creator' => $schema->string()->description('A director or an author, to tell apart titles of one name.'),
            ]))->required(),
        ];
    }

    protected function answer(Request $request, HttpRequest $http): ResponseFactory|Response
    {
        return $this->relay($this->api->call($http, 'POST', 'lookup', input: $request->only(['titles'])));
    }
}
