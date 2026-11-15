<?php

namespace App\Actions\Media;

use App\Library\Data\ListOverview;
use App\Models\ListItem;
use App\Models\MediaList;
use App\Models\User;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Facades\App;
use LogicException;

/**
 * Card summaries of a user's lists (cover collage = first poster paths),
 * system lists first. Private lists are only included for the owner's own
 * view.
 */
final readonly class BuildListSummaries
{
    /**
     * @return list<ListOverview>
     */
    public function execute(User $owner, bool $includePrivate): array
    {
        // The collage needs a handful of posters, not the whole list — the
        // per-parent eager-load limit keeps big lists cheap. 12 leaves slack
        // for leading items without a poster (filtered below).
        return array_values($owner->lists()
            ->when(! $includePrivate, fn ($query) => $query->where('is_public', true))
            ->withCount('items')
            ->with(['items' => fn (Relation $query) => $query->with(['mediaItem', 'person', 'company'])->limit(12)])
            ->inManualOrder()
            ->get()
            ->map(fn (MediaList $list) => new ListOverview(
                id: $list->id,
                title: $list->title,
                systemKey: $list->system_key,
                displayTitle: $list->displayTitle(),
                isPublic: $list->is_public,
                itemCount: $list->items_count ?? throw new LogicException('The lists are counted by withCount above.'),
                createdAt: $list->created_at?->toIso8601String(),
                // Company logos are drawn for a light background, so the collage
                // flags them to render contained on white; posters / portraits
                // stay cover-cropped.
                posterPaths: array_values($list->items
                    ->map(fn (ListItem $item) => match (true) {
                        $item->mediaItem !== null => self::cover($item->mediaItem->posterIn(App::getLocale()), logo: false),
                        $item->person !== null => self::cover($item->person->profile_path, logo: false),
                        $item->company !== null => self::cover($item->company->logo_path, logo: true),
                        default => null,
                    })
                    ->filter()
                    ->take(4)
                    ->all()),
            ))
            ->all());
    }

    /** @return array{path: string, logo: bool}|null */
    private static function cover(?string $path, bool $logo): ?array
    {
        return $path === null ? null : ['path' => $path, 'logo' => $logo];
    }
}
