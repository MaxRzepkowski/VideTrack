<?php

namespace App\Users\Data;

use App\Enums\ActivityGrouping;
use App\Enums\ActivitySort;
use App\Enums\PlayOutcome;
use App\Enums\SortDirection;
use JsonSerializable;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * The state of an activity panel's controls — sorting, grouping and the
 * filters — as its address carries it. Every value is already sane: a stale
 * or hand-edited address falls back rather than failing the page.
 */
#[TypeScript]
final readonly class ActivityFilters implements JsonSerializable
{
    public function __construct(
        public ActivitySort $sort,
        public SortDirection $dir,
        public ActivityGrouping $group,
        /** Title search over the entry's item and its ancestors (a show finds its episodes). */
        public ?string $q = null,
        public ?string $genre = null,
        public ?string $type = null,
        /** Only on a panel whose entries have an outcome (the history). */
        public ?PlayOutcome $status = null,
        /** Calendar days, YYYY-MM-DD. */
        public ?string $from = null,
        public ?string $to = null,
        public bool $hideRated = false,
        public bool $hideListed = false,
    ) {}

    /**
     * The controls' state as the page reads it back.
     *
     * @return array{
     *     sort: value-of<ActivitySort>,
     *     dir: value-of<SortDirection>,
     *     q: string|null,
     *     group: value-of<ActivityGrouping>,
     *     genre: string|null,
     *     type: string|null,
     *     status: value-of<PlayOutcome>|null,
     *     from: string|null,
     *     to: string|null,
     *     hideRated: bool,
     *     hideListed: bool
     * }
     */
    public function jsonSerialize(): array
    {
        return [
            'sort' => $this->sort->value,
            'dir' => $this->dir->value,
            'q' => $this->q,
            'group' => $this->group->value,
            'genre' => $this->genre,
            'type' => $this->type,
            'status' => $this->status?->value,
            'from' => $this->from,
            'to' => $this->to,
            'hideRated' => $this->hideRated,
            'hideListed' => $this->hideListed,
        ];
    }
}
