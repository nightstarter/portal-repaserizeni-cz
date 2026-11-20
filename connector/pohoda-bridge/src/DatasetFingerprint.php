<?php

namespace PortalRepase\Bridge;

final class DatasetFingerprint
{
    public static function calculate(array $rows, array $config)
    {
        $items = array();
        $anomalies = array();
        $references = array();
        $mode = isset($config['quantity_mode']) ? $config['quantity_mode'] : 'unconfirmed';
        foreach ($rows as $row) {
            $reference = trim((string) (isset($row['IDS']) ? $row['IDS'] : ''));
            if ($reference === '') {
                self::increment($anomalies, 'EMPTY_REFERENCE');
                continue;
            }
            if (isset($references[$reference])) {
                self::increment($anomalies, 'DUPLICATE_PRODUCT_REFERENCE');
            }
            $references[$reference] = true;
            try {
                $quantity = QuantityCalculator::calculate(
                    isset($row['StavZ']) ? $row['StavZ'] : null,
                    isset($row['Rezer']) ? $row['Rezer'] : null,
                    $mode
                );
            } catch (BridgeException $exception) {
                self::increment($anomalies, $exception->safeCode());
                continue;
            }
            $items[] = array(
                'product_reference' => $reference,
                'available_quantity' => $quantity['available_quantity'],
                'reserved_quantity' => $quantity['reserved_quantity'],
            );
        }
        usort($items, function ($left, $right) {
            foreach (array('product_reference', 'available_quantity', 'reserved_quantity') as $field) {
                if ($left[$field] == $right[$field]) {
                    continue;
                }
                return $left[$field] < $right[$field] ? -1 : 1;
            }
            return 0;
        });
        ksort($anomalies, SORT_STRING);
        $manifest = array(
            'version' => 1,
            'warehouse_code' => (string) $config['warehouse_code'],
            'items' => $items,
            'source_anomalies' => $anomalies,
        );

        return array(
            'fingerprint' => hash('sha256', Util::json($manifest)),
            'anomalies' => $anomalies,
            'item_count' => count($items),
        );
    }

    private static function increment(array &$values, $key)
    {
        $values[$key] = isset($values[$key]) ? $values[$key] + 1 : 1;
    }
}
