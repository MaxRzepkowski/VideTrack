<?php

namespace Database\Factories;

use App\Models\FranchiseMember;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<FranchiseMember>
 */
class FranchiseMemberFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'franchise' => 'Q462',
            'type' => 'movie',
            'provider' => 'tmdb',
            'ref' => (string) fake()->unique()->numberBetween(1, 9_999_999),
            'title' => fake()->words(2, true),
            'release_date' => '2015-01-01',
            'votes' => 5000,
            'card' => ['year' => 2015],
        ];
    }
}
