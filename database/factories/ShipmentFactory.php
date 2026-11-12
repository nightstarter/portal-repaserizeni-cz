<?php

namespace Database\Factories;

use App\Enums\OrganizationCode;
use App\Enums\ShipmentCarrier;
use App\Enums\ShipmentDirection;
use App\Enums\ShipmentStatus;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class ShipmentFactory extends Factory
{
    public function definition(): array
    {
        return [
            'direction' => ShipmentDirection::AK_TO_PL,
            'carrier' => ShipmentCarrier::GLS,
            'tracking' => null,
            'status' => ShipmentStatus::PREPARED,
            'sender_organization_id' => Organization::factory()->state(['code' => OrganizationCode::AUTOKLUBY]),
            'recipient_organization_id' => Organization::factory()->state(['code' => OrganizationCode::POLAND]),
            'created_by' => User::factory(),
            'sent_at' => null,
            'delivered_at' => null,
            'transport_error_text' => null,
            'lock_version' => 0,
        ];
    }
}
