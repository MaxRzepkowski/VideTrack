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

/** DELETE /api/v1/media/{type}/{ref}/lists/{list}. */
#[Name('remove_from_list')]
#[Description('Take a title, season or episode off one of the user\'s lists.')]
#[IsIdempotent]
final class RemoveFromList extends LibraryTool
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
            ...$this->position($schema),
            'list_id' => $schema->integer()->min(1)->description('The list, from `get_lists`.')->required(),
        ];
    }

    protected function answer(Request $request, HttpRequest $http): ResponseFactory|Response
    {
        return $this->relay($this->api->call($http, 'DELETE', 'media.lists.detach', [...self::at($request), 'list' => $request->integer('list_id')]));
    }
}
