<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;

/**
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    /**
     * Hash of the password every user gets, computed once for all of them.
     *
     * @var string|null
     */
    protected static ?string $password;

    /**
     * Define the model's default state. Every user has the password "password".
     *
     * @return array
     */
    public function definition(): array
    {
        return [
            'uuid' => $this->faker->uuid(),
            'email' => $this->faker->unique()->safeEmail(),
            'email_verified_at' => CarbonImmutable::now(),
            'password' => static::$password ??= Hash::make('password')
        ];
    }

    /**
     * User whose email address is not verified yet.
     *
     * @return static
     */
    public function unverified(): static
    {
        return $this->state(['email_verified_at' => null]);
    }
}
