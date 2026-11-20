<?php

namespace PortalRepase\Bridge;

final class BridgeApplication
{
    private $config;
    private $reader;
    private $http;
    private $spool;
    private $state;
    private $logger;

    public function __construct(array $config, StockReader $reader = null, HttpClient $http = null, FileSpool $spool = null)
    {
        $this->config = $config;
        $this->reader = $reader === null ? new StockReader($config) : $reader;
        $this->http = $http === null ? new HttpClient($config) : $http;
        $this->spool = $spool === null ? new FileSpool($config['spool_dir'], $config['spool_max_bytes'], $config['spool_max_age_hours']) : $spool;
        $this->state = new StateStore($config['state_file']);
        $this->logger = new SafeLogger($config['log_file'], $config['log_max_bytes'], $config['log_retention_days'], array(
            $config['bridge_secret_base64'], $config['sql_password'], $config['sql_dsn']
        ));
    }

    public function stockInspect($limit)
    {
        $result = array();
        foreach ($this->reader->inspect($limit) as $row) {
            try {
                $physical = QuantityCalculator::calculate($row['StavZ'], $row['Rezer'], 'physical');
                $free = QuantityCalculator::calculate($row['StavZ'], $row['Rezer'], 'free');
                $result[] = array(
                    'IDS' => trim((string) $row['IDS']), 'StavZ' => $physical['physical_quantity'],
                    'Rezer' => $physical['reserved_quantity'], 'physical' => $physical['available_quantity'],
                    'free' => $free['available_quantity'], 'quantity_error' => null,
                );
            } catch (BridgeException $exception) {
                $result[] = array(
                    'IDS' => trim((string) $row['IDS']), 'StavZ' => $row['StavZ'], 'Rezer' => $row['Rezer'],
                    'physical' => null, 'free' => null, 'quantity_error' => $exception->safeCode(),
                );
            }
        }
        return $result;
    }

    public function stockDryRun()
    {
        return $this->stockReport(false);
    }

    public function stockAnomalies()
    {
        return $this->stockReport(true);
    }

    private function stockReport($includeDetails)
    {
        $rows = $this->reader->readAll();
        $prefixes = Config::productReferencePrefixes($this->config);
        $ids = array();
        $duplicateIds = array();
        $invalidQuantityIds = array();
        $report = array(
            'product_scope' => 'sql_prefix',
            'prefixes' => $prefixes,
            'rr_cards' => count($rows),
            'unique_ids' => 0,
            'duplicate_ids' => 0,
            'duplicate_rows' => 0,
            'non_integer_quantities' => 0,
            'negative_quantities' => 0,
            'reserved_positive' => 0,
            'physical_differs_from_free' => 0,
            'quantity_mode' => $this->config['quantity_mode'],
        );
        foreach ($rows as $row) {
            $id = trim((string) $row['IDS']);
            if (isset($ids[$id])) {
                $report['duplicate_rows']++;
                $duplicateIds[$id] = true;
            }
            $ids[$id] = true;

            $physical = $this->quantityClass($row['StavZ']);
            $reserved = $this->quantityClass($row['Rezer']);
            if ($physical === 'non_integer' || $reserved === 'non_integer') {
                $report['non_integer_quantities']++;
                $invalidQuantityIds[$id] = true;
            }
            if ($physical === 'negative' || $reserved === 'negative') {
                $report['negative_quantities']++;
                $invalidQuantityIds[$id] = true;
            }
            if (is_numeric($row['Rezer']) && (float) $row['Rezer'] > 0) {
                $report['reserved_positive']++;
            }
            if ($physical === 'integer' && $reserved === 'integer') {
                $physicalValue = (int) $row['StavZ'];
                $freeValue = max($physicalValue - (int) $row['Rezer'], 0);
                if ($physicalValue !== $freeValue) {
                    $report['physical_differs_from_free']++;
                }
            }
        }
        $report['unique_ids'] = count($ids);
        $report['duplicate_ids'] = count($duplicateIds);
        if ($includeDetails) {
            $report['duplicate_id_values'] = array_keys($duplicateIds);
            $report['invalid_quantity_ids'] = array_keys($invalidQuantityIds);
        }
        return $report;
    }

    private function quantityClass($value)
    {
        if (! is_numeric($value)) {
            return 'non_integer';
        }
        $numeric = (float) $value;
        if ($numeric < 0) {
            return 'negative';
        }
        return floor($numeric) === $numeric ? 'integer' : 'non_integer';
    }

    public function syncOnce($dryRun)
    {
        $lock = new ProcessLock();
        $lock->acquire($this->config['lock_file']);
        $runId = Util::uuid();
        try {
            // Fail closed before touching an existing spool. An entry created for
            // another scope must never be sent after a configuration change.
            Config::productReferencePrefixes($this->config);
            if (! $dryRun && ! empty($this->config['dry_run'])) {
                throw new BridgeException('DRY_RUN_CONFIG_ACTIVE');
            }
            if ($this->config['quantity_mode'] === 'unconfirmed') {
                throw new BridgeException('QUANTITY_MODE_UNCONFIRMED');
            }
            if (! $dryRun) {
                $drained = $this->drainSpool($runId);
                if ($drained['fingerprint_mismatch']) {
                    $this->state->remove(array('last_successful_snapshot_run_id', 'dataset_fingerprint', 'last_dataset_confirmed_at'));
                }
                $this->applyDrainedState($drained);
                if ($this->spool->count() > 0) {
                    $this->sendHeartbeatStatus('DEGRADED', array('SNAPSHOT_PENDING_RETRY'));
                    return array('status' => 'DEFERRED_PENDING_SPOOL', 'spool_count' => $this->spool->count());
                }
            }
            $rows = $this->reader->readAll();
            if (! $dryRun) {
                $this->state->set('last_successful_sql_read_at', Util::utcNow());
            }
            $fingerprint = DatasetFingerprint::calculate($rows, $this->config);
            $state = $this->state->read();
            $unchanged = isset($state['last_successful_snapshot_run_id'], $state['dataset_fingerprint'])
                && preg_match('/^[a-f0-9-]{36}$/i', (string) $state['last_successful_snapshot_run_id'])
                && preg_match('/^[a-f0-9]{64}$/', (string) $state['dataset_fingerprint'])
                && hash_equals((string) $state['dataset_fingerprint'], $fingerprint['fingerprint']);
            if ($dryRun) {
                return array(
                    'status' => $unchanged ? 'UNCHANGED_CONFIRMATION_ONLY' : 'FULL_SNAPSHOT_REQUIRED',
                    'dataset_fingerprint' => $fingerprint['fingerprint'],
                    'item_count' => $fingerprint['item_count'],
                    'anomalies' => $fingerprint['anomalies'],
                );
            }
            $mode = $unchanged ? 'UNCHANGED_CONFIRMATION_ONLY' : 'FULL_SNAPSHOT_REQUIRED';
            if ($unchanged) {
                $confirmation = $this->confirmationEntry($state['last_successful_snapshot_run_id'], $fingerprint['fingerprint']);
                $this->spool->enqueue($confirmation);
                $this->logger->write('INFO', 'STOCK_CONFIRMATION_SPOOLED', array('run_id' => $state['last_successful_snapshot_run_id'], 'batch_id' => $confirmation['batch_id']));
            } else {
                $built = (new SnapshotChunker())->build($rows, $this->config, null, null, $fingerprint['fingerprint']);
                $this->spool->enqueueMany($built['chunks']);
                $this->logger->write('INFO', 'SNAPSHOT_SPOOLED', array('run_id' => $built['run_id'], 'chunks' => count($built['chunks'])));
            }
            $drained = $this->drainSpool($runId);
            if ($drained['fingerprint_mismatch']) {
                $this->state->remove(array('last_successful_snapshot_run_id', 'dataset_fingerprint', 'last_dataset_confirmed_at'));
                $built = (new SnapshotChunker())->build($rows, $this->config, null, null, $fingerprint['fingerprint']);
                $this->spool->enqueueMany($built['chunks']);
                $mode = 'FULL_SNAPSHOT_REQUIRED';
                $drained = $this->drainSpool($runId);
            }
            $this->applyDrainedState($drained);
            if ($this->spool->count() === 0) {
                $this->state->set('last_successful_snapshot_at', Util::utcNow());
            }
            $this->sendHeartbeatStatus($this->spool->count() === 0 ? 'OK' : 'DEGRADED', $this->spool->count() === 0 ? array() : array('SNAPSHOT_PENDING_RETRY'));
            $current = $this->state->read();
            return array(
                'status' => $this->spool->count() === 0 ? 'SENT' : 'PENDING_RETRY',
                'mode' => $mode,
                'run_id' => isset($current['last_successful_snapshot_run_id']) ? $current['last_successful_snapshot_run_id'] : null,
                'spool_count' => $this->spool->count(),
            );
        } catch (BridgeException $exception) {
            if (! $dryRun && empty($this->config['dry_run'])) {
                try {
                    $this->sendHeartbeatStatus('ERROR', array($exception->safeCode()));
                } catch (BridgeException $ignored) {
                    $this->logger->write('WARN', 'HEARTBEAT_FAILED', array('run_id' => $runId));
                }
            }
            throw $exception;
        } finally {
            $lock->release();
        }
    }

    private function drainSpool($runId)
    {
        $lastAcknowledged = null;
        $fingerprintMismatch = false;
        while (($entry = $this->spool->oldestReady()) !== null) {
            $expectedScope = Config::productScopeFingerprint($this->config);
            if (! isset($entry['meta']['scope_fingerprint']) || ! hash_equals($expectedScope, (string) $entry['meta']['scope_fingerprint'])) {
                $this->spool->block($entry, 'SPOOL_SCOPE_MISMATCH');
                $this->logger->write('ERROR', 'SPOOL_SCOPE_MISMATCH', array('run_id' => $runId, 'batch_id' => $entry['meta']['batch_id']));
                return array('last_acknowledged' => $lastAcknowledged, 'fingerprint_mismatch' => $fingerprintMismatch);
            }
            try {
                $response = $this->http->signed('POST', $entry['meta']['endpoint'], array(), $entry['payload']);
            } catch (BridgeException $exception) {
                $delay = Util::backoffSeconds((int) $entry['meta']['attempts'] + 1, $this->config['retry_base_seconds'], $this->config['retry_max_seconds']);
                $this->spool->retry($entry, $delay);
                $this->logger->write('WARN', $exception->safeCode(), array('run_id' => $runId, 'batch_id' => $entry['meta']['batch_id']));
                return array('last_acknowledged' => $lastAcknowledged, 'fingerprint_mismatch' => $fingerprintMismatch);
            }
            $this->logger->write('INFO', 'SNAPSHOT_HTTP', array('run_id' => $entry['meta']['run_id'], 'batch_id' => $entry['meta']['batch_id'], 'http_status' => $response['status']));
            if ($response['status'] >= 200 && $response['status'] < 300) {
                $this->spool->acknowledge($entry);
                $lastAcknowledged = $entry['meta'];
                continue;
            }
            $decoded = json_decode($response['body'], true);
            if ($entry['meta']['kind'] === 'UNCHANGED_CONFIRMATION'
                && ($response['status'] === 422
                    || ($response['status'] === 409
                        && is_array($decoded)
                        && isset($decoded['error']['code'])
                        && in_array($decoded['error']['code'], array('DATASET_FINGERPRINT_MISMATCH', 'IDEMPOTENCY_CONFLICT'), true)))) {
                $this->spool->acknowledge($entry);
                $fingerprintMismatch = true;
                break;
            }
            if (HttpClient::shouldRetry($response['status'])) {
                $fallback = Util::backoffSeconds((int) $entry['meta']['attempts'] + 1, $this->config['retry_base_seconds'], $this->config['retry_max_seconds']);
                $this->spool->retry($entry, HttpClient::retryAfter($response, $fallback));
            } else {
                $this->spool->block($entry, 'HTTP_'.$response['status']);
            }
            return array('last_acknowledged' => $lastAcknowledged, 'fingerprint_mismatch' => $fingerprintMismatch);
        }

        return array('last_acknowledged' => $lastAcknowledged, 'fingerprint_mismatch' => $fingerprintMismatch);
    }

    private function confirmationEntry($baseRunId, $fingerprint)
    {
        $confirmationId = Util::uuid();
        return array(
            'batch_id' => $confirmationId,
            'run_id' => $baseRunId,
            'scope_fingerprint' => Config::productScopeFingerprint($this->config),
            'dataset_fingerprint' => $fingerprint,
            'kind' => 'UNCHANGED_CONFIRMATION',
            'endpoint' => '/api/internal/v1/stock-snapshots/confirm-unchanged',
            'payload' => Util::json(array(
                'confirmation_id' => $confirmationId,
                'base_snapshot_run_id' => $baseRunId,
                'dataset_fingerprint' => $fingerprint,
                'warehouse_code' => $this->config['warehouse_code'],
                'source_generated_at' => Util::utcNow(),
            )),
        );
    }

    private function applyDrainedState(array $drained)
    {
        if ($this->spool->count() !== 0 || $drained['last_acknowledged'] === null) {
            return;
        }
        $meta = $drained['last_acknowledged'];
        if (! isset($meta['run_id'], $meta['dataset_fingerprint'])
            || ! preg_match('/^[a-f0-9-]{36}$/i', (string) $meta['run_id'])
            || ! preg_match('/^[a-f0-9]{64}$/', (string) $meta['dataset_fingerprint'])) {
            return;
        }
        $this->state->setMany(array(
            'last_successful_snapshot_run_id' => $meta['run_id'],
            'dataset_fingerprint' => $meta['dataset_fingerprint'],
            'last_dataset_confirmed_at' => Util::utcNow(),
        ));
    }

    public function heartbeatPayload($status, array $errorCodes)
    {
        $state = $this->state->read();
        return array(
            'event_id' => Util::uuid(), 'connector_id' => $this->config['connector_id'],
            'connector_version' => $this->config['connector_version'], 'occurred_at' => Util::utcNow(),
            'last_pohoda_read_at' => isset($state['last_successful_sql_read_at']) ? $state['last_successful_sql_read_at'] : null,
            'last_snapshot_at' => isset($state['last_successful_snapshot_at']) ? $state['last_successful_snapshot_at'] : null,
            'last_command_poll_at' => isset($state['last_command_poll_at']) ? $state['last_command_poll_at'] : null,
            'status' => $status, 'error_codes' => array_values($errorCodes),
        );
    }

    public function sendHeartbeat($dryRun)
    {
        $payload = $this->heartbeatPayload('OK', array());
        if ($dryRun) {
            return $payload;
        }
        return $this->sendHeartbeatStatus('OK', array());
    }

    private function sendHeartbeatStatus($status, array $errorCodes)
    {
        $payload = $this->heartbeatPayload($status, $errorCodes);
        $raw = Util::json($payload);
        $response = $this->http->signed('POST', '/api/internal/v1/heartbeat', array(), $raw);
        if ($response['status'] < 200 || $response['status'] >= 300) {
            throw new BridgeException('HEARTBEAT_HTTP_'.$response['status']);
        }
        return array('status' => 'SENT', 'http_status' => $response['status']);
    }

    public function pollCommands($dryRun)
    {
        if ($dryRun) {
            return array('status' => 'DRY_RUN_NO_NETWORK', 'count' => 0, 'types' => array());
        }
        $lock = new ProcessLock();
        $lock->acquire($this->config['lock_file']);
        try {
            $query = array('connector_id' => $this->config['connector_id'], 'limit' => (int) $this->config['command_limit']);
            $response = $this->http->signed('GET', '/api/internal/v1/commands', $query, '');
            if ($response['status'] !== 200) {
                throw new BridgeException('COMMAND_POLL_HTTP_'.$response['status']);
            }
            $decoded = json_decode($response['body'], true);
            if (! is_array($decoded) || ! isset($decoded['commands']) || ! is_array($decoded['commands'])) {
                throw new BridgeException('COMMAND_RESPONSE_INVALID');
            }
            $this->state->set('last_command_poll_at', Util::utcNow());
            $handler = new CommandHandler();
            $counts = array();
            foreach ($decoded['commands'] as $command) {
                if (! is_array($command)
                    || ! isset($command['command_id'], $command['lease_token'], $command['type'], $command['payload'])
                    || ! preg_match('/^[a-f0-9-]{36}$/i', (string) $command['command_id'])
                    || ! preg_match('/^[a-f0-9-]{36}$/i', (string) $command['lease_token'])
                    || ! is_array($command['payload'])) {
                    throw new BridgeException('COMMAND_SCHEMA_INVALID');
                }
                $type = isset($command['type']) ? (string) $command['type'] : 'INVALID';
                $counts[$type] = isset($counts[$type]) ? $counts[$type] + 1 : 1;
                $result = $handler->execute($command, isset($this->config['environment']) ? $this->config['environment'] : 'production');
                $payload = array(
                    'result_id' => Util::uuid(), 'lease_token' => $command['lease_token'],
                    'status' => $result['status'], 'external_code' => $result['external_code'],
                    'completed_at' => Util::utcNow(), 'metadata' => array('message_code' => $result['external_code'] === null ? 'OK' : $result['external_code']),
                );
                $resultResponse = $this->http->signed('POST', '/api/internal/v1/commands/'.rawurlencode($command['command_id']).'/result', array(), Util::json($payload));
                if ($resultResponse['status'] < 200 || $resultResponse['status'] >= 300) {
                    throw new BridgeException('COMMAND_RESULT_HTTP_'.$resultResponse['status']);
                }
            }
            return array('status' => 'POLLED', 'count' => count($decoded['commands']), 'types' => $counts);
        } finally {
            $lock->release();
        }
    }
}
