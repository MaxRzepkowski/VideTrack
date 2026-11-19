<?php

namespace App\Actions\Media;

use App\Catalog\Data\TypeCount;
use App\Catalog\MediaTypeRegistry;
use App\Models\User;
use App\Support\PageInfo;
use App\Tracking\Data\CountdownEntry;
use App\Tracking\Data\CountdownFilters;
use App\Tracking\Data\EventCountdown;
use App\Tracking\Surfaces\Countdowns;

/**
 * The countdowns page: the user's countdowns (see Countdowns), searched,
 * filtered by kind, sorted and paged, with how many of each kind there are
 * for the type filter.
 */
final readonly class BuildCountdowns
{
    /** Hero cards are large; a dozen fills a page without a wall of scrolling. */
    private const PER_PAGE = 12;

    public function __construct(
        private Countdowns $countdowns,
        private MediaTypeRegistry $types,
    ) {}

    /**
     * @return array{entries: list<CountdownEntry|EventCountdown>, types: list<TypeCount>, filters: array<string, string|null>, pagination: PageInfo}
     */
    public function execute(User $user, CountdownFilters $filters, int $page): array
    {
        $set = $this->countdowns->of($user);

        // Counted before the search, so the numbers describe the whole set;
        // only the kinds actually counting down.
        $counts = $set->counts();

        // "All" unless the requested type is a real, present option: the
        // select offers no other, and an aged link opens the whole list.
        $type = $filters->type !== null && isset($counts[$filters->type]) ? $filters->type : null;

        $filters = new CountdownFilters($filters->sort, $filters->dir, $type, $filters->q);
        $entries = $set->page($filters, $page, self::PER_PAGE);

        // Past the last page the address is stale: the last page instead.
        if ($page > $entries->lastPage()) {
            $entries = $set->page($filters, $entries->lastPage(), self::PER_PAGE);
        }

        return [
            'entries' => array_values($entries->items()),
            'types' => $set->kinds($this->types),
            'filters' => [
                'type' => $type,
                'q' => $filters->q,
                'sort' => $filters->sort->value,
                'dir' => $filters->dir->value,
            ],
            'pagination' => PageInfo::of($entries),
        ];
    }
}
