<?php

namespace PortalRepase\Bridge;

final class Diagnostics
{
    private $config;
    private $http;
    private $reader;

    public function __construct(array $config, HttpClient $http, StockReader $reader)
    {
        $this->config = $config;
        $this->http = $http;
        $this->reader = $reader;
    }

    public function run()
    {
        $checks = array();
        $checks[] = $this->check('CONFIG_LOADED', true, 'OK');
        $checks[] = $this->check('PHP_VERSION', version_compare(PHP_VERSION, '7.4.0', '>='), PHP_VERSION_ID < 80200 ? 'WARN_PHP_74_TECHNICAL_DEBT' : 'OK');
        foreach (array('curl', 'openssl', 'PDO', 'pdo_sqlsrv', 'sqlsrv') as $extension) {
            $checks[] = $this->check('EXT_'.strtoupper($extension), extension_loaded($extension), extension_loaded($extension) ? 'OK' : 'MISSING');
        }
        $varWritable = is_dir($this->config['var_dir']) || @mkdir($this->config['var_dir'], 0700, true);
        $checks[] = $this->check('VAR_WRITABLE', $varWritable && is_writable($this->config['var_dir']), $varWritable ? 'OK' : 'NOT_WRITABLE');

        try {
            $response = $this->http->publicHead('/up');
            $date = isset($response['headers']['date']) ? strtotime($response['headers']['date']) : false;
            $skew = $date === false ? null : abs(time() - $date);
            $checks[] = $this->check('PORTAL_TLS', $response['status'] >= 200 && $response['status'] < 500, 'HTTP_'.$response['status']);
            $checks[] = $this->check('CLOCK_SKEW', $skew !== null && $skew <= 300, $skew === null ? 'DATE_HEADER_MISSING' : 'SECONDS_'.$skew);
        } catch (BridgeException $exception) {
            $checks[] = $this->check('PORTAL_TLS', false, $exception->safeCode());
        }

        try {
            $columns = $this->reader->schemaColumns();
            $required = array('ID', 'IDS', 'StavZ', 'Rezer', 'DatSave', 'RefSklad', 'RelSkTyp');
            $checks[] = $this->check('SQL_CONNECTION', true, 'OK');
            $checks[] = $this->check('SQL_SCHEMA', count(array_diff($required, $columns)) === 0, 'COLUMNS_'.count($columns));
            $checks[] = $this->check('WAREHOUSE_SCOPE', $this->reader->warehouseCount() >= 0, 'REF_'.(int) $this->config['warehouse_ref']);
            $prefixes = Config::productReferencePrefixes($this->config);
            $checks[] = $this->check('PRODUCT_SCOPE', $this->reader->scopedCount() >= 0, 'SQL_PREFIX_'.implode(',', $prefixes));
            $permissions = $this->reader->dangerousPermissions();
            $checks[] = $this->check('SQL_READ_ONLY', $permissions === array(), $permissions === array() ? 'OK' : 'WARN_EXCESS_PERMISSIONS');
        } catch (BridgeException $exception) {
            $checks[] = $this->check('SQL_CONNECTION', false, $exception->safeCode());
        }

        return $checks;
    }

    private function check($name, $ok, $detail)
    {
        return array('check' => $name, 'ok' => (bool) $ok, 'detail' => $detail);
    }
}
