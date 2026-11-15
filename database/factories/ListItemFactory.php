<?php

namespace Database\Factories;

use App\Models\ListItem;
use App\Models\MediaItem;
use App\Models\MediaList;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ListItem>
 */
class ListItemFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'list_id' => MediaList::factory(),
            'media_item_id' => MediaItem::factory(),
            'position' => 1,
        ];
    }
}
