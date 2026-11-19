<?php

namespace App\Http\Mcp\Tools;

use App\Accounts\TokenAbility;
use App\Models\MediaList;
use Illuminate\Http\Request as HttpRequest;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

/**
 * GET /api/v1/lists.
 */
#[Name('get_lists')]
#[Description('The user\'s lists with their ids and sizes. The one with `systemKey` "'.MediaList::SYSTEM_WATCHLIST.'" is the Backlog, where the user keeps what they mean to watch.')]
#[IsReadOnly]
final class GetLists extends LibraryTool
{
    protected function ability(): TokenAbility
    {
        return TokenAbility::Read;
    }

    protected function answer(Request $request, HttpRequest $http): ResponseFactory|Response
    {
        return $this->relay($this->api->call($http, 'GET', 'lists.index'));
    }
}
