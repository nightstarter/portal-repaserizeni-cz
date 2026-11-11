<?php

namespace Database\Factories;

use App\Enums\UserRole;
use App\Models\Organization;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class UserFactory extends Factory
{
    protected static ?string $password = null;

    public function definition(): array
    {
        return [
            'organization_id' => Organization::factory(),
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'role' => UserRole::AK_OPERATOR,
            'preferred_locale' => 'cs',
            'password' => static::$password ??= Hash::make('TestPassword!123'),
            'active' => true,
            'must_change_password' => false,
            'remember_token' => Str::random(10),
        ];
    }
}
