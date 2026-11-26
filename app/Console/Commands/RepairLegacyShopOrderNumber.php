<?php

namespace App\Console\Commands;

use App\Integrations\LegacyShop\LegacyOrderNumberRepairService;
use App\Integrations\LegacyShop\LegacyShopConfigurationException;
use Illuminate\Console\Command;
use Throwable;

final class RepairLegacyShopOrderNumber extends Command
{
    protected $signature = 'legacy-shop:repair-order-number
        {source-order-id : Exact ps_orders.id_order value}
        {--expected-old-order-no= : Required current portal order number}
        {--apply : Apply the correction; without this option the command is dry-run only}';

    protected $description = 'Safely repair one imported legacy shop order number to its exact source order ID';

    public function handle(LegacyOrderNumberRepairService $repair): int
    {
        $sourceOrderId = (string) $this->argument('source-order-id');
        $expectedOldOrderNo = $this->option('expected-old-order-no');
        if (! is_string($expectedOldOrderNo) || $expectedOldOrderNo === '') {
            $this->error('The --expected-old-order-no option is required.');

            return self::INVALID;
        }

        try {
            $status = $repair->run($sourceOrderId, $expectedOldOrderNo, (bool) $this->option('apply'));
        } catch (LegacyShopConfigurationException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        } catch (Throwable $exception) {
            $this->error('Legacy order number repair failed: '.class_basename($exception));

            return self::FAILURE;
        }

        $this->table(['Field', 'Value'], [
            ['status', $status],
            ['source_order_id', $sourceOrderId],
            ['target_order_no', $sourceOrderId],
            ['writes_applied', $status === LegacyOrderNumberRepairService::REPAIRED ? 'yes' : 'no'],
        ]);

        return self::SUCCESS;
    }
}
