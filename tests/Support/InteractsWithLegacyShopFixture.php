<?php

namespace Tests\Support;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

trait InteractsWithLegacyShopFixture
{
    /** @param array<string, mixed> $overrides */
    protected function configureLegacyShopFixture(array $overrides = []): void
    {
        config()->set('database.connections.legacy_shop', [
            'driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '', 'foreign_key_constraints' => false,
        ]);
        DB::purge('legacy_shop');
        $schema = Schema::connection('legacy_shop');

        $schema->create('ps_orders', function (Blueprint $t): void {
            $t->unsignedInteger('id_order')->primary();
            $t->unsignedInteger('id_carrier');
            $t->unsignedInteger('id_customer');
            $t->unsignedInteger('id_cart');
            $t->unsignedInteger('id_address_invoice');
            $t->string('payment')->nullable();
            $t->decimal('total_paid', 12, 2);
            $t->decimal('total_paid_real', 12, 2);
            $t->boolean('valid');
            $t->unsignedTinyInteger('schvaleno')->nullable();
            $t->dateTime('date_add');
        });
        $schema->create('ps_customer', function (Blueprint $t): void {
            $t->unsignedInteger('id_customer')->primary();
            $t->string('firstname');
            $t->string('lastname');
            $t->string('email');
        });
        $schema->create('ps_address', function (Blueprint $t): void {
            $t->unsignedInteger('id_address')->primary();
            $t->unsignedInteger('id_country');
            $t->string('firstname');
            $t->string('lastname');
            $t->string('company')->nullable();
            $t->string('address1');
            $t->string('city');
            $t->string('postcode');
            $t->string('phone')->nullable();
            $t->string('phone_mobile')->nullable();
        });
        $schema->create('ps_country', function (Blueprint $t): void {
            $t->unsignedInteger('id_country')->primary();
            $t->string('iso_code', 3);
        });
        $schema->create('ps_carrier', function (Blueprint $t): void {
            $t->unsignedInteger('id_carrier')->primary();
            $t->string('name');
        });
        $schema->create('ps_cart_vehicle_data', function (Blueprint $t): void {
            $t->unsignedInteger('id_cart');
            $t->string('vin')->nullable();
            $t->string('make')->nullable();
            $t->string('model')->nullable();
            $t->string('year')->nullable();
            $t->string('fuel')->nullable();
            $t->string('power')->nullable();
            $t->string('capacity')->nullable();
        });
        $schema->create('ps_order_history', function (Blueprint $t): void {
            $t->unsignedInteger('id_order_history')->primary();
            $t->unsignedInteger('id_order');
            $t->unsignedInteger('id_order_state');
            $t->dateTime('date_add');
        });
        $schema->create('ps_order_detail', function (Blueprint $t): void {
            $t->unsignedInteger('id_order_detail')->primary();
            $t->unsignedInteger('id_order');
            $t->unsignedInteger('product_id');
            $t->string('product_reference')->nullable();
            $t->string('product_name')->nullable();
        });
        $schema->create('ps_product', function (Blueprint $t): void {
            $t->unsignedInteger('id_product')->primary();
            $t->decimal('deposit_amount', 12, 2)->nullable();
        });
        $schema->create('ps_payment_cc', function (Blueprint $t): void {
            $t->unsignedInteger('id_payment_cc')->primary();
            $t->unsignedInteger('id_order');
        });
        $schema->create('ps_paypal_order', function (Blueprint $t): void {
            $t->unsignedInteger('id_order')->primary();
            $t->string('payment_status')->nullable();
        });

        DB::connection('legacy_shop')->table('ps_country')->insert(['id_country' => 1, 'iso_code' => 'CZ']);
        DB::connection('legacy_shop')->table('ps_carrier')->insert([
            ['id_carrier' => 19, 'name' => 'Osobní odběr v Děčíně'],
            ['id_carrier' => 20, 'name' => 'Osobní odběr v Děčíně'],
            ['id_carrier' => 30, 'name' => 'GLS'],
        ]);

        $base = [
            'enabled' => true,
            'import_from' => null,
            'minimum_order_id' => 100,
            'batch_size' => 100,
            'prestashop14' => [
                'table_prefix' => 'ps_',
                'paid_state_ids' => [2],
                'carrier_handover_map' => ['19' => 'PERSONAL_PICKUP', '20' => 'PERSONAL_PICKUP', '30' => 'GLS'],
            ],
            'mapping' => ['verified' => true, 'product_classifications' => []],
        ];
        config()->set('integrations.legacy_shop', array_replace_recursive($base, $overrides));
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @param  array<int, array<string, mixed>>|null  $details
     */
    protected function insertLegacyOrder(int $id, array $overrides = [], ?array $details = null): void
    {
        $db = DB::connection('legacy_shop');
        $order = array_replace([
            'id_order' => $id, 'id_carrier' => 19, 'id_customer' => $id, 'id_cart' => $id,
            'id_address_invoice' => $id, 'payment' => 'Bankovní převod', 'total_paid' => '1000.00',
            'total_paid_real' => '1000.00', 'valid' => 1, 'schvaleno' => 1, 'date_add' => '2026-11-10 12:00:00',
        ], (array) ($overrides['order'] ?? []));
        $db->table('ps_orders')->insert($order);
        $db->table('ps_customer')->insert(array_replace([
            'id_customer' => $id, 'firstname' => 'Fixture', 'lastname' => 'Customer', 'email' => "fixture-{$id}@example.test",
        ], (array) ($overrides['customer'] ?? [])));
        $db->table('ps_address')->insert(array_replace([
            'id_address' => $id, 'id_country' => 1, 'firstname' => 'Fixture', 'lastname' => 'Customer',
            'company' => null, 'address1' => 'Fixture 1', 'city' => 'Test City', 'postcode' => '10000',
            'phone' => '+420000000000', 'phone_mobile' => null,
        ], (array) ($overrides['address'] ?? [])));
        $db->table('ps_cart_vehicle_data')->insert(array_replace([
            'id_cart' => $id, 'vin' => 'TESTVIN00000000000', 'make' => 'Fixture', 'model' => 'Vehicle',
            'year' => '2020', 'fuel' => null, 'power' => null, 'capacity' => null,
        ], (array) ($overrides['vehicle'] ?? [])));
        $db->table('ps_order_history')->insert(array_replace([
            'id_order_history' => $id * 10, 'id_order' => $id, 'id_order_state' => 2, 'date_add' => '2026-11-10 12:05:00',
        ], (array) ($overrides['history'] ?? [])));

        $details ??= [[
            'id_order_detail' => $id * 10, 'id_order' => $id, 'product_id' => 100000 + $id,
            'product_reference' => 'REP-'.$id, 'product_name' => 'Fixture steering rack',
        ]];
        foreach ($details as $detail) {
            $detail = array_replace(['id_order' => $id], $detail);
            $db->table('ps_order_detail')->insert($detail);
            $db->table('ps_product')->insertOrIgnore([
                'id_product' => $detail['product_id'],
                'deposit_amount' => $detail['product_id'] === 91173 ? null : '200.00',
            ]);
            if ($detail['product_id'] !== 91173 && ! empty($detail['product_reference'])) {
                $classifications = (array) config('integrations.legacy_shop.mapping.product_classifications', []);
                $classifications[$detail['product_reference']] = [
                    'technical_type' => 'EPS',
                    'routing' => 'ARGO',
                ];
                if ((int) $order['schvaleno'] === 1) {
                    $classifications[$detail['product_reference']]['workflow_scenario'] = 'STOCK_DECIN';
                }
                config()->set('integrations.legacy_shop.mapping.product_classifications', $classifications);
            }
        }
    }
}
