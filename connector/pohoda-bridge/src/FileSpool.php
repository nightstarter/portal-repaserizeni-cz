<?php

namespace PortalRepase\Bridge;

final class FileSpool
{
    private $directory;
    private $maxBytes;
    private $maxAgeSeconds;

    public function __construct($directory, $maxBytes, $maxAgeHours)
    {
        $this->directory = $directory;
        $this->maxBytes = max(1024, (int) $maxBytes);
        $this->maxAgeSeconds = max(1, (int) $maxAgeHours) * 3600;
        if (! is_dir($directory) && ! mkdir($directory, 0700, true) && ! is_dir($directory)) {
            throw new BridgeException('SPOOL_DIRECTORY_CREATE_FAILED');
        }
    }

    public function enqueue(array $chunk)
    {
        $this->assertCapacity(strlen($chunk['payload']));
        $prefix = sprintf('%020d-%s', (int) round(microtime(true) * 1000000), $chunk['batch_id']);
        $payloadPath = $this->directory.DIRECTORY_SEPARATOR.$prefix.'.payload';
        $metaPath = $this->directory.DIRECTORY_SEPARATOR.$prefix.'.meta.json';
        Util::atomicWrite($payloadPath, $chunk['payload']);
        try {
            Util::atomicWrite($metaPath, Util::json(array(
                'batch_id' => $chunk['batch_id'], 'run_id' => $chunk['run_id'],
                'scope_fingerprint' => isset($chunk['scope_fingerprint']) ? $chunk['scope_fingerprint'] : null,
                'dataset_fingerprint' => isset($chunk['dataset_fingerprint']) ? $chunk['dataset_fingerprint'] : null,
                'kind' => isset($chunk['kind']) ? $chunk['kind'] : 'FULL_SNAPSHOT',
                'endpoint' => isset($chunk['endpoint']) ? $chunk['endpoint'] : '/api/internal/v1/stock-snapshots',
                'created_at' => time(), 'attempts' => 0, 'next_attempt_at' => 0,
            )));
        } catch (BridgeException $exception) {
            @unlink($payloadPath);
            throw $exception;
        }
    }

    public function enqueueMany(array $chunks)
    {
        $bytes = 0;
        foreach ($chunks as $chunk) {
            $bytes += strlen($chunk['payload']);
        }
        $this->assertCapacity($bytes);
        foreach ($chunks as $chunk) {
            $this->enqueue($chunk);
        }
    }

    public function oldestReady($now = null)
    {
        $now = $now === null ? time() : (int) $now;
        $files = glob($this->directory.DIRECTORY_SEPARATOR.'*.meta.json') ?: array();
        sort($files, SORT_STRING);
        foreach ($files as $metaPath) {
            $meta = json_decode((string) file_get_contents($metaPath), true);
            if (! is_array($meta)) {
                throw new BridgeException('SPOOL_METADATA_INVALID');
            }
            if ($now - (int) $meta['created_at'] > $this->maxAgeSeconds) {
                throw new BridgeException('SPOOL_OLDEST_ITEM_EXPIRED');
            }
            if ((int) $meta['next_attempt_at'] > $now) {
                return null;
            }
            $payloadPath = substr($metaPath, 0, -strlen('.meta.json')).'.payload';
            if (! is_file($payloadPath)) {
                throw new BridgeException('SPOOL_PAYLOAD_MISSING');
            }
            return array('meta_path' => $metaPath, 'payload_path' => $payloadPath, 'meta' => $meta, 'payload' => (string) file_get_contents($payloadPath));
        }
        return null;
    }

    public function retry(array $entry, $delay)
    {
        $meta = $entry['meta'];
        $meta['attempts'] = (int) $meta['attempts'] + 1;
        $meta['next_attempt_at'] = time() + max(1, (int) $delay);
        Util::atomicWrite($entry['meta_path'], Util::json($meta));
    }

    public function block(array $entry, $code)
    {
        $meta = $entry['meta'];
        $meta['blocked_code'] = preg_replace('/[^A-Z0-9_.-]/', '_', strtoupper((string) $code));
        $meta['next_attempt_at'] = PHP_INT_MAX;
        Util::atomicWrite($entry['meta_path'], Util::json($meta));
    }

    public function acknowledge(array $entry)
    {
        if (! @unlink($entry['payload_path']) || ! @unlink($entry['meta_path'])) {
            throw new BridgeException('SPOOL_ACK_FAILED');
        }
    }

    public function count()
    {
        return count(glob($this->directory.DIRECTORY_SEPARATOR.'*.meta.json') ?: array());
    }

    private function assertCapacity($additionalBytes)
    {
        $total = 0;
        foreach (glob($this->directory.DIRECTORY_SEPARATOR.'*') ?: array() as $file) {
            if (is_file($file)) {
                $total += filesize($file);
            }
        }
        if ($total + (int) $additionalBytes > $this->maxBytes) {
            throw new BridgeException('SPOOL_CAPACITY_EXCEEDED');
        }
        $this->oldestReady(time());
    }
}
