<?php

namespace App\Catalog\Data;

use App\Catalog\DiscoverFilterKind;
use JsonSerializable;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * One filter the provider offers on the discover page, described by the
 * provider and rendered generically by the frontend — a new filter is a change
 * inside a provider module, never in the core or in Vue.
 *
 * `unavailable` separates the two failures that look alike from the outside:
 * a filter with no options is one the region simply has nothing for (hide it),
 * while an unavailable filter is one whose dictionary could not be fetched. The
 * difference matters because an unavailable filter that the URL is actively
 * using must NOT quietly widen the results — the caller degrades instead.
 */
#[TypeScript]
final readonly class DiscoverFilter implements JsonSerializable
{
    private const FIRST_YEAR = 1900;

    /** How far past this year a release can already be dated. */
    private const YEARS_AHEAD = 5;

    /**
     * @param  string  $key  `genres`, `year`, `sort`, `watch_provider`, `status`, `platform`
     * @param  string  $label  translated server-side
     * @param  list<DiscoverFilterOption>  $options  ordered; empty for Range
     * @param  string|null  $default  a Select's resting value, left out of the URL
     * @param  bool  $unavailable  the dictionary behind the options did not load
     * @param  bool  $open  numeric provider refs outside options remain valid
     * @param  int|null  $rangeMin  lower bound for a numeric range
     * @param  int|null  $rangeMax  upper bound for a numeric range
     */
    public function __construct(
        public string $key,
        public string $label,
        public DiscoverFilterKind $kind,
        public array $options = [],
        public ?string $default = null,
        public bool $unavailable = false,
        public bool $open = false,
        public ?int $rangeMin = null,
        public ?int $rangeMax = null,
    ) {}

    /**
     * @return array{
     *     key: string,
     *     label: string,
     *     kind: value-of<DiscoverFilterKind>,
     *     options: list<DiscoverFilterOption>,
     *     default: string|null,
     *     unavailable: bool,
     *     open: bool,
     *     rangeMin: int|null,
     *     rangeMax: int|null
     * }
     */
    public function jsonSerialize(): array
    {
        return [
            'key' => $this->key,
            'label' => $this->label,
            'kind' => $this->kind->value,
            'options' => $this->options,
            'default' => $this->default,
            'unavailable' => $this->unavailable,
            'open' => $this->open,
            'rangeMin' => $this->rangeMin,
            'rangeMax' => $this->rangeMax,
        ];
    }

    /** The release-year span every provider offers: from the dawn of cinema to the announced years ahead. */
    public static function years(): self
    {
        return new self('year', __('discover.year'), DiscoverFilterKind::Range, rangeMin: self::FIRST_YEAR, rangeMax: now()->year + self::YEARS_AHEAD);
    }
}
