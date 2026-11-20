<?php

namespace PortalRepase\Bridge;

final class CommandHandler
{
    public function execute(array $command, $environment)
    {
        $type = isset($command['type']) ? (string) $command['type'] : '';
        $payload = isset($command['payload']) && is_array($command['payload']) ? $command['payload'] : array();
        if ($this->containsSql($payload)) {
            return array('status' => 'ERROR', 'external_code' => 'SQL_PAYLOAD_FORBIDDEN');
        }
        if ($type === 'NOOP' && $environment === 'testing') {
            return array('status' => 'SUCCESS', 'external_code' => null);
        }
        return array('status' => 'ERROR', 'external_code' => 'UNKNOWN_COMMAND_TYPE');
    }

    private function containsSql(array $payload)
    {
        foreach ($payload as $key => $value) {
            if (in_array(strtolower((string) $key), array('sql', 'query', 'statement', 'connection', 'password', 'secret'), true)) {
                return true;
            }
            if (is_array($value) && $this->containsSql($value)) {
                return true;
            }
        }
        return false;
    }
}
