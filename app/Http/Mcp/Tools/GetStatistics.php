<?php

namespace App\Http\Mcp\Tools;

use App\Accounts\TokenAbility;
use App\Users\Statistics\PeriodKind;
use App\Users\Statistics\StatisticsPeriod;
use App\Users\Statistics\StatisticsSection;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Http\Request as HttpRequest;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

/**
 * GET /api/v1/statistics and /api/v1/statistics/{section}.
 */
#[Name('get_statistics')]
#[Description('The user\'s statistics for a year (their current one by default), a range of days or the whole history, from their own history: time spent (minutes) and counts per media type, ratings and what became of the titles; or one section in depth. To compare two periods, call once for each.')]
#[IsReadOnly]
final class GetStatistics extends LibraryTool
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
            'section' => $schema->string()->enum(array_column(StatisticsSection::cases(), 'value'))->description(
                'What to count. '.StatisticsSection::Summary->value.' (when left out): time, counts, ratings, what became of the titles. '
                .StatisticsSection::Activity->value.': when things were finished (by month, weekday, hour). '
                .StatisticsSection::Taste->value.': the titles that took the most time, release years, genres, lengths. '
                .StatisticsSection::Origins->value.': countries, languages, networks or studios. '
                .StatisticsSection::Creators->value.': the cast, directors, writers and authors met most. '
                .StatisticsSection::Ratings->value.': the user\'s ratings against everyone else\'s. '
                .StatisticsSection::Records->value.': longest streak of days, busiest day, biggest marathon.',
            ),
            'period' => $schema->string()->pattern(StatisticsPeriod::CODE_PATTERN)
                ->description('A year (2025), or `'.PeriodKind::All->value.'` for the whole history. The user\'s current year when period, from and to are left out.'),
            'from' => $schema->string()->format('date')->description('The first day of a range (YYYY-MM-DD), with `to`.'),
            'to' => $schema->string()->format('date')->description('The last day of a range (YYYY-MM-DD), with `from`.'),
            'type' => $schema->string()->enum($this->types->titleCodes())->description('Only this media type; every type when left out.'),
        ];
    }

    protected function answer(Request $request, HttpRequest $http): ResponseFactory|Response
    {
        return $this->relay($this->api->call(
            $http,
            'GET',
            'statistics.show',
            ['section' => $request->string('section', StatisticsSection::Summary->value)->toString()],
            input: $request->only(['period', 'from', 'to', 'type']),
        ));
    }
}
