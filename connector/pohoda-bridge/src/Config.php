<?php

namespace PortalRepase\Bridge;

final class Config
{
    public static function load($path)
    {
        if (! is_file($path)) {
            throw new BridgeException('CONFIG_NOT_FOUND');
        }
        $config = require $path;
        if (! is_array($config)) {
            throw new BridgeException('CONFIG_INVALID');
        }
        self::validate($config);

        return $config;
    }

    public static function validate(array $config)
    {
        $required = array(
            'environment', 'portal_base_url', 'bridge_key_id', 'bridge_secret_base64', 'connector_id', 'connector_version',
            'sql_dsn', 'sql_username', 'sql_password', 'warehouse_ref', 'warehouse_code', 'quantity_mode',
            'connect_timeout_seconds', 'request_timeout_seconds', 'batch_size', 'command_limit',
            'spool_max_bytes', 'spool_max_age_hours', 'retry_base_seconds', 'retry_max_seconds',
            'log_max_bytes', 'log_retention_days', 'var_dir', 'spool_dir', 'state_file', 'log_file', 'lock_file', 'dry_run'
        );
        foreach ($required as $key) {
            if (! array_key_exists($key, $config)) {
                throw new BridgeException('CONFIG_MISSING_'.strtoupper($key));
            }
        }
        if (strpos((string) $config['portal_base_url'], 'https://') !== 0) {
            throw new BridgeException('PORTAL_HTTPS_REQUIRED');
        }
        $url = parse_url((string) $config['portal_base_url']);
        if (! is_array($url) || ! isset($url['host']) || isset($url['user']) || isset($url['pass']) || isset($url['query']) || isset($url['fragment'])) {
            throw new BridgeException('PORTAL_URL_INVALID');
        }
        if (! preg_match('/^[A-Za-z0-9._-]{1,64}$/', (string) $config['bridge_key_id'])) {
            throw new BridgeException('BRIDGE_KEY_ID_INVALID');
        }
        if (! preg_match('/^[A-Za-z0-9._-]{1,64}$/', (string) $config['connector_id'])) {
            throw new BridgeException('CONNECTOR_ID_INVALID');
        }
        $secret = base64_decode((string) $config['bridge_secret_base64'], true);
        if ($secret === false || strlen($secret) < 32) {
            throw new BridgeException('BRIDGE_SECRET_TOO_SHORT');
        }
        if (! in_array(isset($config['quantity_mode']) ? $config['quantity_mode'] : 'unconfirmed', array('unconfirmed', 'physical', 'free'), true)) {
            throw new BridgeException('QUANTITY_MODE_INVALID');
        }
        if ((int) (isset($config['batch_size']) ? $config['batch_size'] : 500) < 1 || (int) $config['batch_size'] > 500) {
            throw new BridgeException('BATCH_SIZE_INVALID');
        }
    }
}
