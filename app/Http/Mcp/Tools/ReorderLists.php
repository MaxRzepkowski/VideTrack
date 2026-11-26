<?php

namespace App\Http\Mcp\Tools;

use App\Accounts\TokenAbility;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Http\Request as HttpRequest;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;

/** PUT /api/v1/lists/order. */
#[Name('reorder_lists')]
#[Description('Put the user\'s lists in a new order. `list_ids` are their `id`s from `get_lists`, first to last; lists left out keep their order after those given, and ids of other users\' lists are ignored. The Backlog always comes first and takes no part.')]
#[IsIdempotent]
final class ReorderLists extends LibraryTool
{
    protected function ability(): TokenAbility
    {
        return TokenAbility::Write;
    }

    /**
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'list_ids' => $schema->array()->min(1)->items($schema->integer()->min(1))->description('List ids from `get_lists`, in the new order.')->required(),
        ];
    }

    protected function answer(Request $request, HttpRequest $http): ResponseFactory|Response
    {
        return $this->relay($this->api->call($http, 'PUT', 'lists.reorder', input: $request->only(['list_ids'])));
    }
}
