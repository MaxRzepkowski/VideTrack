<?php

namespace App\Http\Mcp\Tools;

use App\Accounts\TokenAbility;
use Illuminate\Http\Request as HttpRequest;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

/**
 * GET /api/v1/up-next.
 */
#[Name('get_up_next')]
#[Description('What the user watches next: for every show they are watching, the first episode they have not seen yet, with how far along the show they are.')]
#[IsReadOnly]
final class GetUpNext extends LibraryTool
{
    protected function ability(): TokenAbility
    {
        return TokenAbility::Read;
    }

    protected function answer(Request $request, HttpRequest $http): ResponseFactory|Response
    {
        return $this->relay($this->api->call($http, 'GET', 'up-next'));
    }
}
