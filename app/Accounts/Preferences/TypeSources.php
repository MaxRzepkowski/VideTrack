<?php

namespace App\Accounts\Preferences;

use App\Support\Payload;
use App\Tracking\Surface;
use App\Tracking\TrackingSource;
use stdClass;

/**
 * The kinds of title with tracking sources of their own: type => surface =>
 * the rows switched on there. Read from what is stored, what no longer means
 * anything (a surface or a row that is gone, a wrong shape, the views with one
 * of their two halves) left out.
 */
final readonly class TypeSources
{
    /**
     * @param  array<string, array<value-of<Surface>, list<TrackingSource>>>  $types
     */
    private function __construct(private array $types) {}

    public static function fromStored(mixed $stored): self
    {
        $types = [];

        foreach (Payload::named($stored) as $type => $surfaces) {
            $own = [];

            foreach (Surface::cases() as $surface) {
                $rows = Payload::map($surfaces)[$surface->value] ?? null;

                if (is_array($rows)) {
                    $own[$surface->value] = array_values(array_unique(
                        array_filter(array_map(TrackingSource::tryFrom(...), Payload::strings($rows))),
                        SORT_REGULAR,
                    ));
                }
            }

            // The views share one switch: a part of them alone was never chosen.
            $views = array_map(fn (Surface $view): string => $view->value, Surface::views());
            $taken = array_intersect($views, array_keys($own));

            if ($taken !== [] && count($taken) !== count($views)) {
                $own = array_diff_key($own, array_flip($views));
            }

            if ($own !== []) {
                $types[$type] = $own;
            }
        }

        return new self($types);
    }

    /**
     * The rows a kind of title takes on a surface, or null where it follows the defaults.
     *
     * @return list<TrackingSource>|null
     */
    public function rows(string $type, Surface $surface): ?array
    {
        return $this->types[$type][$surface->value] ?? null;
    }

    /**
     * As the page reads them: a map even when empty, which an empty PHP array
     * would not reach it as.
     *
     * @return array<string, array<value-of<Surface>, list<value-of<TrackingSource>>>>|stdClass
     */
    public function toWire(): array|stdClass
    {
        if ($this->types === []) {
            return new stdClass;
        }

        return array_map(
            fn (array $surfaces): array => array_map(fn (array $rows): array => array_map(fn (TrackingSource $row): string => $row->value, $rows), $surfaces),
            $this->types,
        );
    }
}
