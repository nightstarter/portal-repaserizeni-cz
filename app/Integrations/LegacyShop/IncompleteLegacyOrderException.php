<?php

namespace App\Integrations\LegacyShop;

use RuntimeException;

class IncompleteLegacyOrderException extends RuntimeException
{
    /**
     * @param  array<int, string>  $fields
     */
    public function __construct(public readonly array $fields)
    {
        parent::__construct('Incomplete legacy order: '.implode(', ', $fields));
    }
}
