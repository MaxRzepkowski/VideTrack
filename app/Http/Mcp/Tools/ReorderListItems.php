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

/** PUT /api/v1/lists/{list}/items/order. */
#[Name('reorder_list_items')]
#[Description('Put what is on one of the user\'s lists in a new order. `item_ids` are the `id`s of its items from `get_list`, first to last; items left out keep their order after those given, and ids from other lists are ignored.')]
#[IsIdempotent]
final class ReorderListItems extends LibraryTool
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
            'list_id' => $schema->integer()->min(1)->description('The list, from `get_lists`.')->required(),
            'item_ids' => $schema->array()->min(1)->items($schema->integer()->min(1))->description('Item ids from `get_list`, in the new order.')->required(),
        ];
    }

    protected function answer(Request $request, HttpRequest $http): ResponseFactory|Response
    {
        return $this->relay($this->api->call($http, 'PUT', 'lists.items.reorder', ['list' => $request->integer('list_id')], input: $request->only(['item_ids'])));
    }
}
