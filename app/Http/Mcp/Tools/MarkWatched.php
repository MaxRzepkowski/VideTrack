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
 * POST /api/v1/media/{type}/{ref}/plays. Marking again is a rewatch, as
 * everywhere, so the tool is not idempotent.
 */
#[Name('mark_watched')]
#[Description('Mark a title, season or episode as watched (played, read). Marking a show or a season marks every aired episode not yet watched; with `next_episode` only the episode the user is at. Marking something already watched records a rewatch.')]
#[IsDestructive(false)]
final class MarkWatched extends LibraryTool
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
            'watched_at' => $schema->string()->description('When: "now", "release" (the day it came out), a day (YYYY-MM-DD) or a time (YYYY-MM-DDTHH:MM), not in the future. Now when left out.'),
            'next_episode' => $schema->boolean()->description('On a show or a season: mark only the episode the user is at.'),
            'amount' => $schema->integer()->min(1)->description('What it took, in the type\'s unit (a game: minutes played).'),
        ];
    }

    protected function answer(Request $request, HttpRequest $http): ResponseFactory|Response
    {
        return $this->relay($this->api->call($http, 'POST', 'media.plays.store', self::at($request), [
            'watched_at' => $request->string('watched_at', 'now')->toString(),
            ...$request->only(['next_episode', 'amount']),
        ]));
    }
}
