<?php

namespace App\Http\Mcp\Tools;

use App\Accounts\TokenAbility;
use App\Http\Requests\Api\V1\StoreListRequest;
use App\Http\Requests\Api\V1\UpdateListRequest;
use App\Support\Payload;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Http\Request as HttpRequest;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;

/**
 * PATCH /api/v1/lists/{list}, which takes the whole list: what the assistant
 * left out is read from the list first.
 */
#[Name('update_list')]
#[Description('Rename a list, describe it, or make it public or private. What is left out stays as it is.')]
#[IsIdempotent]
final class UpdateList extends LibraryTool
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
            'list_id' => $schema->integer()->min(1)->required(),
            'title' => $schema->string()->max(StoreListRequest::MAX_TITLE_LENGTH),
            'description' => $schema->string()->max(UpdateListRequest::MAX_DESCRIPTION_LENGTH),
            'is_public' => $schema->boolean(),
        ];
    }

    protected function answer(Request $request, HttpRequest $http): ResponseFactory|Response
    {
        $list = ['list' => $request->integer('list_id')];
        $current = $this->api->call($http, 'GET', 'lists.show', $list);

        if (! $current->succeeded()) {
            return $this->refusal($current);
        }

        $was = Payload::named($current->object()['data'] ?? null);

        return $this->relay($this->api->call($http, 'PATCH', 'lists.update', $list, [
            'title' => $request->filled('title') ? $request->string('title')->toString() : Payload::string($was, 'title'),
            'description' => $request->has('description') ? $request->string('description')->toString() : Payload::string($was, 'description'),
            'is_public' => $request->has('is_public') ? $request->boolean('is_public') : Payload::bool($was, 'isPublic') === true,
        ]));
    }
}
