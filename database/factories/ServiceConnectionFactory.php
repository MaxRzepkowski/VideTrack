<?php

namespace Database\Factories;

use App\Integrations\Connections\ConnectionStatus;
use App\Models\ServiceConnection;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ServiceConnection>
 */
class ServiceConnectionFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'service' => 'simkl',
            'credentials' => ['access_token' => 'testing-token'],
            'status' => ConnectionStatus::Active,
            'account_label' => fake()->userName(),
        ];
    }

    public function expired(): static
    {
        return $this->state(['status' => ConnectionStatus::Expired]);
    }
}
