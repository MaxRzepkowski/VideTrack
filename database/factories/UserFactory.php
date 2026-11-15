<?php

namespace Database\Factories;

use App\Enums\UserRole;
use App\Models\MediaList;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    /**
     * The current password being used by the factory.
     */
    protected static ?string $password;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'username' => fake()->unique()->regexify('[a-z][a-z0-9_]{7,14}'),
            'email' => fake()->unique()->safeEmail(),
            'email_verified_at' => now(),
            'password' => static::$password ??= Hash::make('password'),
            'remember_token' => Str::random(10),
            ...$this->calendarToken(),
        ];
    }

    /**
     * What every account is made with (CreateAccount): its watchlist and its
     * calendar feed token.
     */
    public function configure(): static
    {
        return $this->afterCreating(fn (User $user) => $user->lists()->create(['system_key' => MediaList::SYSTEM_WATCHLIST]));
    }

    /**
     * @return array{calendar_token: string, calendar_token_hash: string}
     */
    private function calendarToken(): array
    {
        $token = Str::random(48);

        return ['calendar_token' => $token, 'calendar_token_hash' => User::calendarTokenHash($token)];
    }

    /**
     * Indicate that the model's email address should be unverified.
     */
    public function unverified(): static
    {
        return $this->state(fn (array $attributes) => [
            'email_verified_at' => null,
        ]);
    }

    public function admin(): static
    {
        return $this->state(['role' => UserRole::Admin]);
    }

    public function blocked(): static
    {
        return $this->state(['blocked_at' => now()]);
    }

    public function private(): static
    {
        return $this->state(['is_private' => true]);
    }
}
