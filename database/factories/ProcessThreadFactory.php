<?php

namespace Database\Factories;

use App\Enums\ProcessThreadType;
use App\Enums\ResponsibleParty;
use App\Models\Order;
use Illuminate\Database\Eloquent\Factories\Factory;

class ProcessThreadFactory extends Factory
{
    public function definition(): array
    {
        return [
            'order_id' => Order::factory(),
            'type' => ProcessThreadType::CUSTOM_REBUILD,
            'state' => 'WAITING_CUSTOMER_ITEM',
            'responsible_party' => ResponsibleParty::CUSTOMER,
            'responsible_organization_id' => null,
            'vendor_routing' => null,
            'warranty_cycle' => null,
            'eta' => null,
            'completed_at' => null,
            'lock_version' => 0,
        ];
    }
}
