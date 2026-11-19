<?php

namespace App\Http\Mcp\Tools;

use App\Accounts\TokenAbility;
use App\Catalog\CatalogSearch;
use App\Catalog\Data\SearchScopes;
use App\Catalog\MediaTypeRegistry;
use App\Http\Mcp\ApiV1;
use App\Http\Mcp\Compact;
use App\Http\Requests\Api\V1\SearchRequest;
use App\Support\Payload;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Http\Request as HttpRequest;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

/**
 * GET /api/v1/search, asked by what is wanted rather than by catalogue: the
 * assistant names a media type (or person, company, collection) and the
 * scope that serves it is found here. A catalogue answers several types at
 * once, so the results are cut to the type and to a few.
 */
#[Name('search')]
#[Description('Find titles, people, companies or collections by name. Each result carries its `type` and `ref` (what every other tool takes) and, for a title, the user\'s `state`. To check many titles at once, use `check_titles`.')]
#[IsReadOnly]
final class Search extends LibraryTool
{
    /** Results when the assistant asks for no number. */
    private const DEFAULT_LIMIT = 5;

    private const MAX_LIMIT = 20;

    public function __construct(ApiV1 $api, MediaTypeRegistry $types, private readonly CatalogSearch $search)
    {
        parent::__construct($api, $types);
    }

    protected function ability(): TokenAbility
    {
        return TokenAbility::Read;
    }

    /**
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        $scopes = $this->search->scopes();
        $first = $scopes->resolve('');

        return [
            'query' => $schema->string()->max(SearchRequest::MAX_QUERY_LENGTH)->description('The name, or part of it.')->required(),
            'type' => $schema->string()->enum(array_merge(...array_values($scopes->scopes)))
                ->description('What to look for. Left out: '.implode(', ', $first === null ? [] : $scopes->types($first)).'.'),
            'limit' => $schema->integer()->min(1)->max(self::MAX_LIMIT)->description('How many results, '.self::DEFAULT_LIMIT.' when left out.'),
        ];
    }

    protected function answer(Request $request, HttpRequest $http): ResponseFactory|Response
    {
        $type = $request->string('type')->toString();
        $query = $request->string('query')->toString();
        $answer = $this->api->call($http, 'GET', 'search', input: array_filter(['q' => $query, 'scope' => $type === '' ? null : $this->search->scopes()->scopeFor($type)]));

        if (! $answer->succeeded()) {
            return $this->refusal($answer);
        }

        $results = array_values(array_filter(
            $answer->items(),
            fn (array $result): bool => $type === '' || SearchScopes::isEntity($type) || Payload::string($result, 'type') === $type,
        ));

        return Response::structured([
            'data' => Compact::of(array_slice($results, 0, $request->integer('limit', self::DEFAULT_LIMIT))),
            'meta' => ['query' => $query, 'found' => count($results)],
        ]);
    }
}
