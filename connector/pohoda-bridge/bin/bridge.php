<?php

require dirname(__DIR__).DIRECTORY_SEPARATOR.'src'.DIRECTORY_SEPARATOR.'autoload.php';

use PortalRepase\Bridge\BridgeApplication;
use PortalRepase\Bridge\BridgeException;
use PortalRepase\Bridge\Config;
use PortalRepase\Bridge\Diagnostics;
use PortalRepase\Bridge\HttpClient;
use PortalRepase\Bridge\StockReader;
use PortalRepase\Bridge\Util;

function bridge_output($value)
{
    echo Util::json($value).PHP_EOL;
}

$arguments = $argv;
array_shift($arguments);
$command = isset($arguments[0]) ? $arguments[0] : 'help';
$dryRun = in_array('--dry-run', $arguments, true);

if ($command === 'key:generate') {
    bridge_output(array('secret_base64' => base64_encode(random_bytes(32))));
    exit(0);
}

if ($command === 'help') {
    bridge_output(array('commands' => array(
        'diagnose', 'key:generate', 'stock:inspect', 'stock:dry-run',
        'stock:anomalies',
        'heartbeat:dry-run', 'sync:once --dry-run', 'sync:once',
        'commands:poll --dry-run', 'commands:poll'
    )));
    exit(0);
}

$configPath = getenv('POHODA_BRIDGE_CONFIG');
if (! is_string($configPath) || $configPath === '') {
    $configPath = dirname(__DIR__).DIRECTORY_SEPARATOR.'config'.DIRECTORY_SEPARATOR.'config.local.php';
}

try {
    $config = Config::load($configPath);
    if ($command === 'diagnose') {
        $checks = (new Diagnostics($config, new HttpClient($config), new StockReader($config)))->run();
        bridge_output(array('checks' => $checks));
        foreach ($checks as $check) {
            if (! $check['ok'] && strpos($check['detail'], 'WARN_') !== 0) {
                exit(2);
            }
        }
        exit(0);
    }

    $application = new BridgeApplication($config);
    switch ($command) {
        case 'stock:inspect':
            bridge_output(array('items' => $application->stockInspect(20)));
            break;
        case 'stock:dry-run':
            bridge_output($application->stockDryRun());
            break;
        case 'stock:anomalies':
            bridge_output($application->stockAnomalies());
            break;
        case 'heartbeat:dry-run':
            bridge_output($application->sendHeartbeat(true));
            break;
        case 'sync:once':
            bridge_output($application->syncOnce($dryRun));
            break;
        case 'commands:poll':
            bridge_output($application->pollCommands($dryRun));
            break;
        default:
            throw new BridgeException('UNKNOWN_CLI_COMMAND');
    }
} catch (BridgeException $exception) {
    bridge_output(array('status' => 'ERROR', 'code' => $exception->safeCode()));
    exit(1);
} catch (Exception $exception) {
    bridge_output(array('status' => 'ERROR', 'code' => 'UNEXPECTED_ERROR'));
    exit(1);
}
