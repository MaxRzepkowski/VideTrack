<?php

namespace App\Actions\Media;

use App\Library\Data\PaletteList;
use App\Models\MediaList;
use App\Models\User;
use App\Support\QueryRow;
use LogicException;

/**
 * The lists a term names, for the command palette: the viewer's own first, in
 * the order they keep them, then the collections the instance keeps, by name.
 */
final readonly class SearchLists
{
    /**
     * @return list<PaletteList>
     */
    public function execute(User $viewer, string $term, int $limit): array
    {
        $own = $viewer->lists()
            ->named($term)
            ->withCount('items')
            ->inManualOrder()
            ->limit($limit)
            ->get()
            ->map(fn (MediaList $list): PaletteList => $this->entry($list, route('users.lists.show', ['user' => $viewer->username, 'list' => $list->id], absolute: false)));

        $room = $limit - $own->count();

        if ($room <= 0) {
            return array_values($own->all());
        }

        $collections = MediaList::query()
            ->collections()
            ->named($term)
            ->withCount('items')
            ->orderBy('title')
            ->limit($room)
            ->get()
            ->map(fn (MediaList $list): PaletteList => $this->entry($list, route('collections.show', $list->collectionSource()
                ?? throw new LogicException('The collections scope reads collections alone.'), absolute: false)));

        return [...array_values($own->all()), ...array_values($collections->all())];
    }

    private function entry(MediaList $list, string $href): PaletteList
    {
        return new PaletteList(
            id: $list->id,
            title: $list->displayTitle(),
            href: $href,
            itemCount: QueryRow::int($list, 'items_count'),
            isCollection: $list->isCollection(),
        );
    }
}
