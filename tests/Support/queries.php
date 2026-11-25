<?php

use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;

/*
 * What a piece of work asks of the database, counted the same way by every
 * test that cares.
 */

/**
 * The queries a piece of work runs, in the order it runs them, and none the
 * test ran before or after it.
 *
 * @return list<QueryExecuted>
 */
function queriesRunBy(Closure $work): array
{
    $queries = [];
    $listening = true;

    DB::listen(function (QueryExecuted $query) use (&$queries, &$listening): void {
        if ($listening) {
            $queries[] = $query;
        }
    });

    try {
        $work();
    } finally {
        $listening = false;
    }

    return $queries;
}

/**
 * The same queries asked for more data as for less, each as often: none
 * runs once per row. A longer `in (…)` list is the same query. A failure
 * lists how often each query ran in either run.
 *
 * @param  list<QueryExecuted>  $smaller  the work's queries on less data
 * @param  list<QueryExecuted>  $larger  the same work's on more
 */
function expectSameQueries(array $smaller, array $larger): void
{
    $times = fn (array $queries): array => array_count_values(array_map(
        fn (QueryExecuted $query): string => (string) preg_replace('/\((?:\?|\d+)(?:, (?:\?|\d+))*\)/', '(…)', $query->sql),
        $queries,
    ));
    [$before, $after] = [$times($smaller), $times($larger)];
    $change = array_filter(array_map(
        fn (string $query): ?string => ($before[$query] ?? 0) === ($after[$query] ?? 0) ? null : sprintf('%d → %d× %s', $before[$query] ?? 0, $after[$query] ?? 0, $query),
        array_keys($before + $after),
    ));

    expect($change)->toBe([], "Other queries ran once the data grew:\n".implode("\n", $change));
}
