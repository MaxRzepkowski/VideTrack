<?php

namespace App\Http\Mcp\Tools;

use App\Accounts\TokenAbility;
use App\Http\Requests\Api\V1\PageRequest;
use App\Tracking\Data\EventCountdown;
use App\Tracking\Surfaces\CountdownSet;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Http\Request as HttpRequest;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

/**
 * GET /api/v1/countdowns, soonest first.
 */
#[Name('get_countdowns')]
#[Description('Every premiere the user counts down to, soonest first and up to ten years ahead: each title they follow, a show by its next episode only, and the showcases their countdowns show (`event`). `meta.types` counts every kind.')]
#[IsReadOnly]
final class GetCountdowns extends LibraryTool
{
    protected function ability(): TokenAbility
    {
        return TokenAbility::Read;
    }

    /**
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'type' => $schema->string()->enum(CountdownSet::kindCodes($this->types))
                ->description('Only this kind: a media type (an episode counts under its show) or `'.EventCountdown::FILTER_TYPE.'`.'),
            'query' => $schema->string()->max(CountdownSet::MAX_QUERY)->description('Only the titles whose name (or show\'s name) contains this.'),
            'page' => $schema->integer()->min(1)->description('The page, from 1.'),
            'per_page' => $schema->integer()->min(1)->max(PageRequest::MAX_PER_PAGE)->description('Countdowns per page, '.PageRequest::DEFAULT_PER_PAGE.' when left out.'),
        ];
    }

    protected function answer(Request $request, HttpRequest $http): ResponseFactory|Response
    {
        return $this->relay($this->api->call($http, 'GET', 'countdowns', input: [
            ...$request->only(['type', 'page', 'per_page']),
            ...$request->filled('query') ? ['q' => $request->string('query')->toString()] : [],
        ]));
    }
}
