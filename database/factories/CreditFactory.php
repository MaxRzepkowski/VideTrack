<?php

namespace Database\Factories;

use App\Catalog\Department;
use App\Models\Credit;
use App\Models\MediaItem;
use App\Models\Person;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Credit>
 */
class CreditFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'media_item_id' => MediaItem::factory(),
            'person_id' => Person::factory(),
            'department' => Department::Acting->value,
            'role' => fake()->name(),
            'order' => 0,
        ];
    }
}
