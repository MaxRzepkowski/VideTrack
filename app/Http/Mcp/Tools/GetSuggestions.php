<?php

namespace App\Http\Mcp\Tools;

use App\Accounts\TokenAbility;
use App\Catalog\MediaTypeRegistry;
use App\Http\Mcp\ApiV1;
use App\Suggestions\SuggestionSources;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Http\Request as HttpRequest;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

/**
 * GET /api/v1/suggestions and /api/v1/suggestions/{shelf}.
 */
#[Name('get_suggestions')]
#[Description('Suggestions worked out from the user\'s own library: left out, the kinds that have something (with how many titles each); with `shelf`, that kind\'s titles, each with the facts that put it there.')]
#[IsReadOnly]
final class GetSuggestions extends LibraryTool
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
            'shelf' => $schema->string()->enum($this->shelves->codes())->description('A kind of suggestion, by its key.'),
        ];
    }

    protected function answer(Request $request, HttpRequest $http): ResponseFactory|Response
    {
        return $this->relay($request->filled('shelf')
            ? $this->api->call($http, 'GET', 'suggestions.show', ['shelf' => $request->string('shelf')->toString()])
            : $this->api->call($http, 'GET', 'suggestions.index'));
    }

    public function __construct(ApiV1 $api, MediaTypeRegistry $types, private readonly SuggestionSources $shelves)
    {
        parent::__construct($api, $types);
    }
}
