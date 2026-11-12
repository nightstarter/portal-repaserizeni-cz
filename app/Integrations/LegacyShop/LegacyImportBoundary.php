<?php

namespace App\Integrations\LegacyShop;

use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Throwable;

class LegacyImportBoundary
{
    public function __construct(
        public readonly ?CarbonImmutable $from,
        public readonly ?int $minimumOrderId,
    ) {
        if ($from === null && $minimumOrderId === null) {
            throw new LegacyShopConfigurationException('LEGACY_SHOP_IMPORT_FROM or LEGACY_SHOP_MIN_ORDER_ID is required.');
        }
    }

    public static function fromConfig(): self
    {
        $rawFrom = config('integrations.legacy_shop.import_from');
        $rawMinimum = config('integrations.legacy_shop.minimum_order_id');
        $legacyTimezone = (string) config('database.connections.legacy_shop.timezone', '+00:00');

        try {
            $from = is_string($rawFrom) && $rawFrom !== ''
                ? CarbonImmutable::parse($rawFrom, $legacyTimezone)->setTimezone($legacyTimezone)
                : null;
        } catch (Throwable) {
            throw new LegacyShopConfigurationException('LEGACY_SHOP_IMPORT_FROM or LEGACY_SHOP_DB_TIMEZONE is invalid.');
        }

        if ($rawMinimum !== null && $rawMinimum !== '' && filter_var($rawMinimum, FILTER_VALIDATE_INT) === false) {
            throw new LegacyShopConfigurationException('LEGACY_SHOP_MIN_ORDER_ID must be an integer.');
        }

        $minimum = $rawMinimum !== null && $rawMinimum !== '' ? (int) $rawMinimum : null;

        return new self($from, $minimum);
    }

    public function applyPrestaShop14(Builder $query): void
    {
        if ($this->minimumOrderId !== null) {
            $query->where('o.id_order', '>=', $this->minimumOrderId);
        }

        if ($this->from !== null) {
            $query->where('o.date_add', '>=', $this->from->format('Y-m-d H:i:s'));
        }
    }
}
