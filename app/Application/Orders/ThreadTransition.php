<?php

namespace App\Application\Orders;

use App\Enums\OrganizationCode;
use App\Enums\ResponsibleParty;

final readonly class ThreadTransition
{
    public function __construct(
        public string $toState,
        public ResponsibleParty $responsibleParty,
        public ?OrganizationCode $responsibleOrganization,
        public bool $completed,
        public array $metadata = [],
        public ?string $reason = null,
        public bool $keepEta = false,
        public mixed $eta = null,
    ) {}
}
