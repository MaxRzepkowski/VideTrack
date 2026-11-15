<?php

namespace Database\Factories;

use App\Enums\MediaUserStatus;
use App\Models\MediaItem;
use App\Models\MediaUserState;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<MediaUserState>
 */
class MediaUserStateFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'media_item_id' => MediaItem::factory()->game(),
            'status' => MediaUserStatus::InProgress,
        ];
    }

    public function onHold(): static
    {
        return $this->state(['status' => MediaUserStatus::OnHold]);
    }
}
