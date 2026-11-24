<?php

namespace App\Operations\Contracts;

interface TlsCertificateInspector
{
    /** @return array{ok:bool,code:string,days_remaining:?int} */
    public function inspect(string $host, int $port, int $timeoutSeconds): array;
}
