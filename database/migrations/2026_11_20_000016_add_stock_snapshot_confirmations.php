<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('stock_snapshot_runs', function (Blueprint $table): void {
            $table->char('dataset_fingerprint', 64)->nullable()->after('rejected_count');
            $table->index(['warehouse_code', 'dataset_fingerprint'], 'stock_run_fingerprint_index');
        });

        Schema::create('stock_snapshot_confirmations', function (Blueprint $table): void {
            $table->id();
            $table->uuid('confirmation_id')->unique();
            $table->foreignId('stock_snapshot_run_id')->constrained('stock_snapshot_runs')->restrictOnDelete();
            $table->string('key_id', 64);
            $table->string('warehouse_code', 32);
            $table->char('dataset_fingerprint', 64);
            $table->timestamp('source_generated_at');
            $table->timestamp('received_at')->index();
            $table->timestamps();
            $table->index(['stock_snapshot_run_id', 'received_at'], 'stock_confirmation_run_received_index');
            $table->index(['warehouse_code', 'received_at'], 'stock_confirmation_warehouse_received_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_snapshot_confirmations');
        Schema::table('stock_snapshot_runs', function (Blueprint $table): void {
            $table->dropIndex('stock_run_fingerprint_index');
            $table->dropColumn('dataset_fingerprint');
        });
    }
};
