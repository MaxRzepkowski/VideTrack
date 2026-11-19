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
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;

/**
 * PUT /api/v1/media/{type}/{ref}/progress.
 */
#[Name('log_progress')]
#[Description('Log progress on a book, game, manga or comic in its unit (pages, minutes, chapters, issues): `add` a session, or `set` the total so far.')]
#[IsDestructive(false)]
final class LogProgress extends LibraryTool
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
            'add' => $schema->integer()->min(1)->description('How much this session took.'),
            'set' => $schema->integer()->min(0)->description('The total so far, replacing the logged sessions.'),
        ];
    }

    protected function answer(Request $request, HttpRequest $http): ResponseFactory|Response
    {
        return $this->relay($this->api->call($http, 'PUT', 'media.progress.update', self::at($request), $request->only(['add', 'set'])));
    }
}
