<?php

namespace App\Integrations\LegacyShop;

use Illuminate\Database\Connection;

class LegacySchemaInspector
{
    public function __construct(private readonly Connection $connection) {}

    /**
     * @return array<int, string>
     */
    public function tables(): array
    {
        if ($this->connection->getDriverName() === 'sqlite') {
            $rows = $this->connection->select("SELECT name FROM sqlite_master WHERE type = 'table' AND name NOT LIKE 'sqlite_%' ORDER BY name");

            return array_map(fn (object $row): string => (string) $row->name, $rows);
        }

        $tables = [];
        foreach ($this->connection->select('SHOW TABLES') as $row) {
            $tables[] = (string) array_values((array) $row)[0];
        }
        sort($tables);

        return $tables;
    }

    /**
     * @return array<int, string>
     */
    public function columns(string $table): array
    {
        $this->assertIdentifier($table);

        if ($this->connection->getDriverName() === 'sqlite') {
            $rows = $this->connection->select("PRAGMA table_info(`{$table}`)");

            return array_map(fn (object $row): string => (string) $row->name, $rows);
        }

        $rows = $this->connection->select("SHOW COLUMNS FROM `{$table}`");

        return array_map(fn (object $row): string => (string) $row->Field, $rows);
    }

    public function hasTable(string $table): bool
    {
        return in_array($table, $this->tables(), true);
    }

    public function serverVersion(): string
    {
        if ($this->connection->getDriverName() === 'sqlite') {
            return (string) $this->connection->selectOne('SELECT sqlite_version() AS version')->version;
        }

        return (string) $this->connection->selectOne('SELECT VERSION() AS version')->version;
    }

    private function assertIdentifier(string $identifier): void
    {
        if (preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/D', $identifier) !== 1) {
            throw new LegacyShopConfigurationException('Unsafe legacy schema identifier.');
        }
    }
}
