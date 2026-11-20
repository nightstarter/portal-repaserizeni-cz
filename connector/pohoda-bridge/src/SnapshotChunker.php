<?php

namespace PortalRepase\Bridge;

final class SnapshotChunker
{
    public function build(array $rows, array $config, $sourceGeneratedAt = null, $runId = null, $datasetFingerprint = null)
    {
        $mode = isset($config['quantity_mode']) ? $config['quantity_mode'] : 'unconfirmed';
        if ($mode === 'unconfirmed') {
            throw new BridgeException('QUANTITY_MODE_UNCONFIRMED');
        }
        $items = array();
        $rejected = 0;
        foreach ($rows as $row) {
            $reference = trim((string) $row['IDS']);
            if ($reference === '') {
                $rejected++;
                continue;
            }
            try {
                $quantities = QuantityCalculator::calculate($row['StavZ'], $row['Rezer'], $mode);
            } catch (BridgeException $exception) {
                $rejected++;
                continue;
            }
            $items[] = array(
                'product_reference' => $reference,
                'available_quantity' => $quantities['available_quantity'],
                'reserved_quantity' => $quantities['reserved_quantity'],
                'source_row_version' => (string) $row['ID'].':'.self::dateVersion($row['DatSave']),
            );
        }
        $batchSize = max(1, min(500, (int) $config['batch_size']));
        $parts = array_chunk($items, $batchSize);
        if ($parts === array()) {
            $parts = array(array());
        }
        $runId = $runId === null ? Util::uuid() : $runId;
        $sourceGeneratedAt = $sourceGeneratedAt === null ? Util::utcNow() : $sourceGeneratedAt;
        $datasetFingerprint = $datasetFingerprint === null
            ? DatasetFingerprint::calculate($rows, $config)['fingerprint']
            : (string) $datasetFingerprint;
        $count = count($parts);
        $chunks = array();
        foreach ($parts as $index => $part) {
            $batchId = Util::uuid();
            $payload = array(
                'snapshot_run_id' => $runId,
                'batch_id' => $batchId,
                'chunk_index' => $index,
                'chunk_count' => $count,
                'final_chunk' => $index === $count - 1,
                'source_generated_at' => $sourceGeneratedAt,
                'warehouse_code' => $config['warehouse_code'],
                'dataset_fingerprint' => $datasetFingerprint,
                'items' => $part,
            );
            $chunks[] = array(
                'run_id' => $runId,
                'batch_id' => $batchId,
                'index' => $index,
                'count' => $count,
                'scope_fingerprint' => Config::productScopeFingerprint($config),
                'dataset_fingerprint' => $datasetFingerprint,
                'kind' => 'FULL_SNAPSHOT',
                'endpoint' => '/api/internal/v1/stock-snapshots',
                'payload' => Util::json($payload),
            );
        }

        return array('run_id' => $runId, 'dataset_fingerprint' => $datasetFingerprint, 'chunks' => $chunks, 'accepted_local' => count($items), 'rejected_local' => $rejected);
    }

    private static function dateVersion($value)
    {
        if ($value instanceof \DateTimeInterface) {
            return $value->format('Y-m-d\TH:i:s.uP');
        }
        return trim((string) $value);
    }
}
