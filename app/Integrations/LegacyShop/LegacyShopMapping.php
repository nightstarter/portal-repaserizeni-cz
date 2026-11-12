<?php

namespace App\Integrations\LegacyShop;

class LegacyShopMapping
{
    /** @param array<string, mixed> $config */
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
        if ($this->productClassifications() === []) {
            throw new LegacyShopConfigurationException('No verified PrestaShop product classifications are configured.');
        }
    }

    public function verified(): bool
    {
        return ($this->config['verified'] ?? false) === true;
    }

    /** @return array<string, array<string, string>> */
    public function productClassifications(): array
    {
        return (array) ($this->config['product_classifications'] ?? []);
    }

    /** @return array<string, string> */
    public function classificationFor(?string $productCode): array
    {
        if ($productCode === null) {
            return [];
        }
        $classification = $this->productClassifications()[$productCode] ?? [];

        return is_array($classification) ? $classification : [];
    }
}
