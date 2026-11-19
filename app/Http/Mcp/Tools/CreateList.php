<?php

namespace App\Http\Mcp\Tools;

use App\Accounts\TokenAbility;
use App\Http\Requests\Api\V1\StoreListRequest;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Http\Request as HttpRequest;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;

/**
 * POST /api/v1/lists.
 */
#[Name('create_list')]
#[Description('Create a list for the user. Answers with its id; fill it with `add_to_list`.')]
#[IsDestructive(false)]
final class CreateList extends LibraryTool
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
            'title' => $schema->string()->max(StoreListRequest::MAX_TITLE_LENGTH)->required(),
        ];
    }

    protected function answer(Request $request, HttpRequest $http): ResponseFactory|Response
    {
        return $this->relay($this->api->call($http, 'POST', 'lists.store', input: ['title' => $request->string('title')->toString()]));
    }
}
