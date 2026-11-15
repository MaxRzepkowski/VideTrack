<?php

namespace App\Actions\Media;

use App\Catalog\Data\DiscoverFilter;
use App\Catalog\Data\DiscoverFilterOption;
use App\Catalog\DiscoverFilterKind;

/**
 * Turns the raw `f` query parameter of the discover page into the filter map
 * providers are given. Every rule here answers the same question:
 * what should a LINK do once it has aged? Nothing aborts — an unknown key, a
 * genre the provider dropped, a reversed year span are all shrugged off, and
 * the page renders whatever is left. The one loud case (an unknown by-name
 * `genre=`) is the page's, not ours.
 *
 * The result is keyed in DEFINITION order, not in the order the query string
 * happened to carry, so the canonical URL and the cache key of two equivalent
 * requests are identical.
 */
final readonly class NormalizeDiscoverFilters
{
    /** Nothing older is worth offering; TMDB and IGDB both thin out below it. */
    private const MIN_YEAR = 1900;

    /** Announced-but-undated titles sit a few years out. */
    private const YEARS_AHEAD = 5;

    /**
     * @param  list<DiscoverFilter>  $definitions
     * @param  mixed  $raw  the `f` parameter — trusted for nothing
     * @return array<string, string|list<string>|array{from: int|null, to: int|null}>
     */
    public function execute(array $definitions, mixed $raw): array
    {
        if (! is_array($raw)) {
            return [];
        }

        $filters = [];

        foreach ($definitions as $definition) {
            $value = match ($definition->kind) {
                DiscoverFilterKind::Select => $this->select($definition, $raw[$definition->key] ?? null),
                DiscoverFilterKind::MultiSelect => $this->multiSelect($definition, $raw[$definition->key] ?? null),
                DiscoverFilterKind::Range => $this->numberRange(
                    $definition,
                    $raw[$definition->key.'_from'] ?? null,
                    $raw[$definition->key.'_to'] ?? null,
                ),
            };

            if ($value !== null) {
                $filters[$definition->key] = $value;
            }
        }

        return $filters;
    }

    /**
     * One option, or nothing. The comparison is a strict string one — `"0"` is
     * a perfectly good TMDB status and must survive where `empty()` would eat
     * it.
     */
    private function select(DiscoverFilter $definition, mixed $raw): ?string
    {
        if (! is_string($raw) || $raw === '') {
            return null;
        }

        // The resting value is not a filter: it stays out of the URL, out of
        // the count and out of the provider call, so picking it by hand and
        // never touching the control lead to the same address.
        if ($raw === $definition->default) {
            return null;
        }

        return $this->offered($definition, $raw) ? $raw : null;
    }

    /**
     * Any number of options, deduped and re-ordered to the provider's order —
     * so ?f[genres][]=18&f[genres][]=28 and the same pair the other way round
     * are one URL and one cache entry.
     *
     * @return list<string>|null
     */
    private function multiSelect(DiscoverFilter $definition, mixed $raw): ?array
    {
        // A single checkbox arrives as a bare string when the URL is typed by
        // hand (?f[genres]=18) rather than built by the page.
        $values = is_string($raw) ? [$raw] : $raw;

        if (! is_array($values)) {
            return null;
        }

        $wanted = [];

        foreach ($values as $value) {
            if (is_string($value) && $value !== '' && $this->offered($definition, $value)) {
                if (! in_array($value, $wanted, true)) {
                    $wanted[] = $value;
                }
            }
        }

        if ($wanted === []) {
            return null;
        }

        // An unavailable filter has no options to order by; keep what came in.
        if ($definition->unavailable) {
            return $wanted;
        }

        $ordered = array_values(array_filter(
            array_map(fn (DiscoverFilterOption $option): string => $option->value, $definition->options),
            fn (string $value): bool => in_array($value, $wanted, true),
        ));

        if ($definition->open) {
            foreach ($wanted as $value) {
                if (! in_array($value, $ordered, true)) {
                    $ordered[] = $value;
                }
            }
        }

        return $ordered === [] ? null : $ordered;
    }

    /**
     * A span whose ends are independent: either may stand alone, and a span
     * given backwards is a mistake with an obvious reading, so it is swapped
     * rather than dropped.
     *
     * @return array{from: int|null, to: int|null}|null
     */
    private function numberRange(DiscoverFilter $definition, mixed $rawFrom, mixed $rawTo): ?array
    {
        $min = $definition->rangeMin ?? self::MIN_YEAR;
        $max = $definition->rangeMax ?? now()->year + self::YEARS_AHEAD;
        $from = $this->number($rawFrom, $min, $max);
        $to = $this->number($rawTo, $min, $max);

        if ($from === null && $to === null) {
            return null;
        }

        if ($from !== null && $to !== null && $from > $to) {
            [$from, $to] = [$to, $from];
        }

        return ['from' => $from, 'to' => $to];
    }

    /** A non-negative integer, clamped into the range the provider offers. */
    private function number(mixed $raw, int $min, int $max): ?int
    {
        if (! is_string($raw) || preg_match('/^\d+$/', $raw) !== 1) {
            return null;
        }

        // Fewer digits than the smallest value allowed is a number cut short
        // (a year typed as "19"), not one to clamp up to the minimum.
        if ($min > 0 && strlen(ltrim($raw, '0')) < strlen((string) $min)) {
            return null;
        }

        return max($min, min($max, (int) $raw));
    }

    /**
     * Is the value one the provider offered? A filter whose dictionary did not
     * load has no options to check against, and rejecting its value would
     * silently widen the results — the caller needs to see it to degrade
     * instead.
     */
    private function offered(DiscoverFilter $definition, string $value): bool
    {
        if ($definition->unavailable) {
            return true;
        }

        if ($definition->open && preg_match('/^\d+$/', $value) === 1) {
            return true;
        }

        foreach ($definition->options as $option) {
            // Both runs of a directional sort are the provider's own values.
            if ($option->value === $value || $option->ascending === $value) {
                return true;
            }
        }

        return false;
    }
}
