<?php

namespace App\Http\Mcp\Tools;

use App\Accounts\TokenAbility;
use App\Http\Requests\Api\V1\AddListItemsRequest;
use App\Library\ListAddOutcome;
use App\Models\MediaList;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Http\Request as HttpRequest;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;

/** POST /api/v1/lists/{list}/items. */
#[Name('add_to_list')]
#[Description('Put titles, seasons or episodes on one of the user\'s lists (`get_lists`; the Backlog is the one with `systemKey` "'.MediaList::SYSTEM_WATCHLIST.'"), up to '.AddListItemsRequest::MAX_ITEMS.' in one call. They go on the end in the order given; one already on the list keeps its place. The answer has no cards: `data` lists each title once, in the order first given, with its `outcome` ("'.ListAddOutcome::Added->value.'"; "'.ListAddOutcome::Present->value.'", already there; "'.ListAddOutcome::NotFound->value.'", its catalogue does not know the ref; "'.ListAddOutcome::Unavailable->value.'", its catalogue could not be asked), and `states` holds the states of those now on the list, keyed `type:ref`.')]
#[IsIdempotent]
final class AddToList extends LibraryTool
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
            'items' => $schema->array()->min(1)->max(AddListItemsRequest::MAX_ITEMS)->items($schema->object($this->position($schema)))
                ->description('The titles, in the order they go on the list.')->required(),
        ];
    }

    protected function answer(Request $request, HttpRequest $http): ResponseFactory|Response
    {
        return $this->relay($this->api->call($http, 'POST', 'lists.items.store', ['list' => $request->integer('list_id')], input: $request->only(['items'])));
    }
}
