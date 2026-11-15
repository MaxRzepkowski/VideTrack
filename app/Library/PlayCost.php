<?php

namespace App\Library;

use App\Catalog\MediaTypeDefinition;
use App\Catalog\MediaTypeRegistry;
use App\Enums\PlayOutcome;

/**
 * What one history entry cost, in its type's unit: the minutes a viewing
 * took, the pages or hours a reading or a playthrough logged. One rule for
 * every total (the dashboard tiles, the history's day headings), in PHP for
 * a row at hand and in SQL for a sum, side by side so they cannot drift.
 *
 * - A progress type (games, reading) counts what the user logged, whether
 *   the attempt was completed or dropped — never the estimate in `runtime`
 *   (a time to beat).
 * - A viewing counts its logged minutes; without them, a completed one costs
 *   its runtime, and an episode the provider left without one costs what its
 *   title declares an episode costs. A dropped viewing without logged
 *   minutes costs nothing: a runtime would be a guess at what was not watched.
 * - An entry on a container (a dropped show) is not a cost: its episodes are.
 */
final readonly class PlayCost
{
    /** The unit of a viewing's cost, beside the progress units. */
    public const WATCH = 'watch';

    public function __construct(private MediaTypeRegistry $types) {}

    /**
     * @return array{0: string, 1: int}|null [unit, amount]; null when the entry cost nothing countable
     */
    public function of(string $type, PlayOutcome $outcome, ?int $amount, ?int $runtime, ?int $titleRuntime): ?array
    {
        $definition = $this->types->has($type) ? $this->types->get($type) : null;

        if ($definition === null || ! $definition->playable) {
            return null;
        }

        $cost = match (true) {
            $definition->progressUnit !== null, $outcome !== PlayOutcome::Completed => $amount ?? 0,
            default => $amount ?? $runtime ?? $titleRuntime ?? 0,
        };

        return $cost > 0 ? [$definition->progressUnit->value ?? self::WATCH, $cost] : null;
    }

    /**
     * The same cost as an SQL expression over a play joined with its item
     * and its title (the projection's root), with its bindings.
     *
     * @return array{0: string, 1: list<string>}
     */
    public function sql(string $plays = 'plays', string $items = 'media_items', string $titles = 'roots'): array
    {
        $counting = array_keys(array_filter($this->types->all(), fn (MediaTypeDefinition $definition): bool => $definition->playable));
        $progress = array_keys(array_filter($this->types->all(), fn (MediaTypeDefinition $definition): bool => $definition->progressUnit !== null));

        $logged = "coalesce({$plays}.amount, 0)";
        $viewing = "case when {$plays}.outcome = ? then coalesce({$plays}.amount, {$items}.runtime, {$titles}.runtime, 0) else {$logged} end";

        $sql = sprintf(
            'case when %s.type not in (%s) then 0 when %s.type in (%s) then %s else %s end',
            $items,
            $this->placeholders($counting),
            $items,
            $this->placeholders($progress),
            $logged,
            $viewing,
        );

        return [$sql, [...$counting, ...$progress, PlayOutcome::Completed->value]];
    }

    /**
     * @param  list<string>  $values
     */
    private function placeholders(array $values): string
    {
        // `in ()` is not SQL; a code no type has stands in for the empty set.
        return $values === [] ? "''" : implode(', ', array_fill(0, count($values), '?'));
    }
}
