<?php

namespace App\Catalog;

use App\Catalog\Data\MediaSummary;
use App\Models\MediaItem;

/**
 * The stored rows of many positions (titles, seasons, episodes) named by
 * type and ref, one query per type rather than one per position. A position
 * nobody stored is missing from the answer, and so is one of a type whose
 * module the instance switched off: it has no provider to find it by.
 */
final readonly class StoredPositions
{
    public function __construct(private MediaTypeRegistry $types) {}

    /**
     * @param  iterable<array{0: string, 1: string}>  $positions  [type, ref] pairs
     * @return array<string, MediaItem> keyed "type:ref"
     */
    public function among(iterable $positions): array
    {
        $refs = [];

        foreach ($positions as [$type, $ref]) {
            if ($this->types->has($type)) {
                $refs[$type][] = $ref;
            }
        }

        $items = [];

        foreach ($refs as $type => $ofType) {
            $found = MediaItem::query()
                ->where('provider', $this->types->get($type)->provider)
                ->where('type', $type)
                ->whereIn('provider_ref', $ofType)
                ->get();

            foreach ($found as $item) {
                $items[MediaSummary::keyOf($item->type, $item->provider_ref)] = $item;
            }
        }

        return $items;
    }
}
