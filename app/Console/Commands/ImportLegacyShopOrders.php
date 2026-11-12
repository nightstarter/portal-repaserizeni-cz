<?php

namespace App\Console\Commands;

use App\Integrations\LegacyShop\LegacyShopConfigurationException;
use App\Integrations\LegacyShop\LegacyShopImportService;
use Illuminate\Console\Command;
use Throwable;

class ImportLegacyShopOrders extends Command
{
    protected $signature = 'legacy-shop:import {--dry-run : Validate and preview without portal writes} {--limit= : Maximum orders to read}';

    protected $description = 'Import paid legacy shop orders after the explicit start boundary';

    public function handle(LegacyShopImportService $importer): int
    {
        $dryRun = (bool) $this->option('dry-run');
        if (! $dryRun && ! config('integrations.legacy_shop.enabled')) {
            $this->error('Legacy shop import is disabled. Use --dry-run or explicitly enable it.');

            return self::FAILURE;
        }

        $rawLimit = $this->option('limit');
        if ($rawLimit !== null && filter_var($rawLimit, FILTER_VALIDATE_INT) === false) {
            $this->error('The --limit option must be an integer.');

            return self::INVALID;
        }

        try {
            $result = $importer->run($dryRun, $rawLimit === null ? null : (int) $rawLimit);
        } catch (LegacyShopConfigurationException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        } catch (Throwable $exception) {
            $this->error('Legacy shop import failed: '.class_basename($exception));

            return self::FAILURE;
        }

        $this->table(['Metric', 'Count'], collect($result->toArray())->map(fn (int|bool $value, string $key): array => [$key, is_bool($value) ? ($value ? 'yes' : 'no') : $value])->values()->all());

        return $result->errors === 0 ? self::SUCCESS : self::FAILURE;
    }
}
