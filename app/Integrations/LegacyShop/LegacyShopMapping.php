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
    }

    public function verified(): bool
    {
        return ($this->config['verified'] ?? false) === true;
    }
}
