<?php

namespace Database\Seeders;

use App\Enums\OrganizationCode;
use App\Models\Organization;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        Organization::query()->upsert([
            ['code' => OrganizationCode::AUTOKLUBY->value, 'name' => 'AUTOKLUBY', 'active' => true],
            ['code' => OrganizationCode::POLAND->value, 'name' => 'POLSKO', 'active' => true],
        ], ['code'], ['name', 'active']);
    }
}
