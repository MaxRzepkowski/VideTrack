<?php

namespace Database\Factories;

use App\Models\MediaList;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<MediaList>
 */
class MediaListFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'title' => fake()->words(3, true),
            'is_public' => true,
        ];
    }

    /** A provider collection's system list: nobody owns it. */
    public function collection(string $provider, string $ref): static
    {
        return $this->state(['user_id' => null, 'system_key' => MediaList::collectionKey($provider, $ref)]);
    }

    public function private(): static
    {
        return $this->state(['is_public' => false]);
    }
}
