<?php

namespace PortalRepase\Bridge;

use RuntimeException;

final class BridgeException extends RuntimeException
{
    private $safeCode;

    public function __construct($safeCode, $message = '')
    {
        $this->safeCode = (string) $safeCode;
        parent::__construct($message !== '' ? $message : (string) $safeCode);
    }

    public function safeCode()
    {
        return $this->safeCode;
    }
}
