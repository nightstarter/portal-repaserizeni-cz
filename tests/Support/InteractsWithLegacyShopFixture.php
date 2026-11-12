<?php

namespace Tests\Support;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

trait InteractsWithLegacyShopFixture
{
    /**
     * @param  array<string, mixed>  $overrides
     */
    protected function configureLegacyShopFixture(array $overrides = []): void
    {
        config()->set('database.connections.legacy_shop', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
            'foreign_key_constraints' => true,
        ]);
        DB::purge('legacy_shop');

        Schema::connection('legacy_shop')->create('fixture_orders', function (Blueprint $table): void {
            $table->unsignedInteger('legacy_id')->primary();
            $table->string('order_number');
            $table->dateTime('legacy_created_at');
            $table->string('payment_state');
            $table->string('customer_name');
            $table->string('customer_company')->nullable();
            $table->string('customer_email')->nullable();
            $table->string('customer_phone')->nullable();
            $table->string('customer_street')->nullable();
            $table->string('customer_city')->nullable();
            $table->string('customer_postal')->nullable();
            $table->string('customer_country')->nullable();
            $table->string('product_code')->nullable();
            $table->string('product_name')->nullable();
            $table->string('vin')->nullable();
            $table->string('vehicle')->nullable();
            $table->string('rebuild_type');
            $table->string('steering_type');
            $table->string('route_code');
            $table->string('scenario_code');
            $table->string('delivery_code');
            $table->decimal('price', 12, 2)->nullable();
            $table->decimal('deposit', 12, 2)->nullable();
        });

        $base = [
            'enabled' => true,
            'import_from' => null,
            'minimum_order_id' => 100,
            'batch_size' => 100,
            'mapping' => [
                'verified' => true,
                'table' => 'fixture_orders',
                'columns' => [
                    'source_order_id' => 'legacy_id',
                    'order_no' => 'order_number',
                    'created_at' => 'legacy_created_at',
                    'payment_status' => 'payment_state',
                    'customer_name' => 'customer_name',
                    'customer_company' => 'customer_company',
                    'customer_email' => 'customer_email',
                    'customer_phone' => 'customer_phone',
                    'customer_street' => 'customer_street',
                    'customer_city' => 'customer_city',
                    'customer_postal_code' => 'customer_postal',
                    'customer_country_code' => 'customer_country',
                    'product_code' => 'product_code',
                    'product_name' => 'product_name',
                    'vin' => 'vin',
                    'vehicle' => 'vehicle',
                    'order_type' => 'rebuild_type',
                    'technical_type' => 'steering_type',
                    'routing' => 'route_code',
                    'workflow_scenario' => 'scenario_code',
                    'customer_handover' => 'delivery_code',
                    'price' => 'price',
                    'deposit' => 'deposit',
                ],
                'paid_values' => ['paid'],
                'value_maps' => [
                    'order_type' => ['custom' => 'CUSTOM_REBUILD', 'stock' => 'STOCK_REBUILD'],
                    'technical_type' => ['eps' => 'EPS', 'hydraulic' => 'HYDRAULIC', 'worm' => 'WORM'],
                    'routing' => ['argo' => 'ARGO', 'kraft' => 'KRAFT'],
                    'workflow_scenario' => ['custom' => 'CUSTOM_REBUILD', 'stock_decin' => 'STOCK_DECIN', 'stock_poland' => 'STOCK_POLAND'],
                    'customer_handover' => ['gls' => 'GLS', 'pickup' => 'PERSONAL_PICKUP'],
                ],
            ],
        ];

        config()->set('integrations.legacy_shop', array_replace_recursive($base, $overrides));
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    protected function insertLegacyOrder(int $id, array $overrides = []): void
    {
        DB::connection('legacy_shop')->table('fixture_orders')->insert(array_merge([
            'legacy_id' => $id,
            'order_number' => 'LEGACY-'.$id,
            'legacy_created_at' => '2026-11-10 12:00:00',
            'payment_state' => 'paid',
            'customer_name' => 'Fixture Customer',
            'customer_company' => null,
            'customer_email' => 'fixture-'.$id.'@example.test',
            'customer_phone' => '+420000000000',
            'customer_street' => 'Fixture 1',
            'customer_city' => 'Test City',
            'customer_postal' => '10000',
            'customer_country' => 'CZ',
            'product_code' => 'REP-'.$id,
            'product_name' => 'Fixture steering rack',
            'vin' => 'TESTVIN00000000'.$id,
            'vehicle' => 'Fixture vehicle',
            'rebuild_type' => 'custom',
            'steering_type' => 'eps',
            'route_code' => 'argo',
            'scenario_code' => 'custom',
            'delivery_code' => 'gls',
            'price' => '1000.00',
            'deposit' => '200.00',
        ], $overrides));
    }
}
