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
 * POST /api/v1/media/{type}/{ref}/cycles.
 */
#[Name('start_rewatch')]
#[Description('Start watching a finished show again from the beginning: its counters start from zero, the earlier run stays in the history.')]
#[IsDestructive(false)]
final class StartRewatch extends LibraryTool
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
        ];
    }

    protected function answer(Request $request, HttpRequest $http): ResponseFactory|Response
    {
        return $this->relay($this->api->call($http, 'POST', 'media.cycles.store', self::at($request)));
    }
}
