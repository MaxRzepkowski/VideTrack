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
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

/**
 * GET /api/v1/calendar, asked for as a start and a number of days: "this
 * week" is how a person asks, and a count cannot end before it starts.
 */
#[Name('get_calendar')]
#[Description('Releases of the titles the user follows, day by day, with the showcases starting on each day.')]
#[IsReadOnly]
final class GetCalendar extends LibraryTool
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
            'from' => $schema->string()->format('date')->description('The first day (YYYY-MM-DD). The user\'s today when left out.'),
            'days' => $schema->integer()->min(1)->description('How many days, the first one included. '.self::DEFAULT_DAYS.' when left out.'),
        ];
    }

    protected function answer(Request $request, HttpRequest $http): ResponseFactory|Response
    {
        return $this->relay($this->api->call($http, 'GET', 'calendar', input: [
            ...$request->only(['from']),
            'days' => $request->integer('days', self::DEFAULT_DAYS),
        ]));
    }

    /** A week, the window a person most often asks about. */
    private const DEFAULT_DAYS = 7;
}
