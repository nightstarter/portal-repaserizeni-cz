<?php

namespace Tests\Unit;

use App\Integrations\LegacyShop\LegacySchemaInspector;
use Illuminate\Database\Connection;
use Mockery;
use PHPUnit\Framework\TestCase;

class LegacySchemaInspectorTest extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_mysql_inspection_uses_show_and_version_without_information_schema_columns(): void
    {
        $connection = Mockery::mock(Connection::class);
        $connection->shouldReceive('getDriverName')->times(3)->andReturn('mysql');
        $connection->shouldReceive('select')->once()->with('SHOW TABLES')->andReturn([
            (object) ['Tables_in_fixture' => 'ps_orders'],
        ]);
        $connection->shouldReceive('select')->once()->with('SHOW COLUMNS FROM `ps_orders`')->andReturn([
            (object) ['Field' => 'id_order'],
            (object) ['Field' => 'date_add'],
        ]);
        $connection->shouldReceive('selectOne')->once()->with('SELECT VERSION() AS version')->andReturn((object) [
            'version' => '5.5-compatible-fixture',
        ]);

        $inspector = new LegacySchemaInspector($connection);

        self::assertSame(['ps_orders'], $inspector->tables());
        self::assertSame(['id_order', 'date_add'], $inspector->columns('ps_orders'));
        self::assertSame('5.5-compatible-fixture', $inspector->serverVersion());
    }
}
