<?php

namespace App\Integrations\LegacyShop;

class LegacyShopMapping
{
    /**
     * @param  array<string, mixed>  $config
     */
    public function __construct(private readonly array $config) {}

    public static function fromConfig(): self
    {
        return new self((array) config('integrations.legacy_shop.mapping', []));
    }

    public function assertImportReady(): void
    {
        if (! $this->verified()) {
            throw new LegacyShopConfigurationException('Legacy mapping has not been explicitly verified.');
        }

        $requiredColumns = [
            'source_order_id', 'order_no', 'payment_status', 'customer_name',
            'product_code', 'order_type', 'technical_type', 'routing',
            'workflow_scenario', 'customer_handover',
        ];
        $missing = array_values(array_filter($requiredColumns, fn (string $field): bool => $this->column($field) === null));

        if ($missing !== []) {
            throw new LegacyShopConfigurationException('Missing legacy column mappings: '.implode(', ', $missing));
        }

        if ($this->paidValues() === []) {
            throw new LegacyShopConfigurationException('No verified paid order values are configured.');
        }

        foreach (['order_type', 'technical_type', 'routing', 'workflow_scenario', 'customer_handover'] as $map) {
            if ($this->valueMap($map) === []) {
                throw new LegacyShopConfigurationException("Missing verified value map: {$map}.");
            }
        }

        $this->assertSafeIdentifier($this->table(), 'table');
        foreach ($this->configuredColumns() as $semantic => $column) {
            $this->assertSafeIdentifier($column, "column {$semantic}");
        }
    }

    public function verified(): bool
    {
        return ($this->config['verified'] ?? false) === true;
    }

    public function table(): string
    {
        $table = $this->config['table'] ?? null;

        if (! is_string($table) || $table === '') {
            throw new LegacyShopConfigurationException('Legacy orders table is not configured.');
        }

        return $table;
    }

    public function column(string $semantic): ?string
    {
        $column = $this->config['columns'][$semantic] ?? null;

        return is_string($column) && $column !== '' ? $column : null;
    }

    /**
     * @return array<string, string>
     */
    public function configuredColumns(): array
    {
        return array_filter(
            (array) ($this->config['columns'] ?? []),
            fn (mixed $column): bool => is_string($column) && $column !== '',
        );
    }

    /**
     * @return array<int, string|int>
     */
    public function paidValues(): array
    {
        return array_values((array) ($this->config['paid_values'] ?? []));
    }

    /**
     * @return array<string, string>
     */
    public function valueMap(string $semantic): array
    {
        return (array) ($this->config['value_maps'][$semantic] ?? []);
    }

    public function mapValue(string $semantic, mixed $raw): ?string
    {
        if ($raw === null || is_array($raw) || is_object($raw)) {
            return null;
        }

        $key = (string) $raw;
        $mapped = $this->valueMap($semantic)[$key] ?? null;

        return is_string($mapped) && $mapped !== '' ? $mapped : null;
    }

    private function assertSafeIdentifier(string $identifier, string $label): void
    {
        if (preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/D', $identifier) !== 1) {
            throw new LegacyShopConfigurationException("Unsafe legacy {$label} identifier.");
        }
    }
}
