<?php

namespace Database\Factories;

use App\Enums\OrganizationCode;
use Illuminate\Database\Eloquent\Factories\Factory;

class OrganizationFactory extends Factory
{
    public function definition(): array
    {
        return ['code' => OrganizationCode::AUTOKLUBY, 'name' => 'AUTOKLUBY', 'active' => true];
    }
}
