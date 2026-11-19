<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bridge_nonces', function (Blueprint $table): void {
            $table->id();
            $table->string('key_id', 64);
            $table->string('nonce', 128);
            $table->timestamp('used_at')->useCurrent();
            $table->timestamp('expires_at')->index();
            $table->unique(['key_id', 'nonce'], 'bridge_nonce_unique');
        });

        Schema::create('stock_snapshot_batches', function (Blueprint $table): void {
            $table->id();
            $table->uuid('batch_id')->unique();
            $table->string('key_id', 64);
            $table->string('warehouse_code', 32);
            $table->timestamp('source_generated_at')->index();
            $table->timestamp('received_at')->useCurrent()->index();
            $table->string('status', 16)->index();
            $table->unsignedInteger('accepted_count')->default(0);
            $table->unsignedInteger('rejected_count')->default(0);
            $table->char('payload_hash', 64);
            $table->timestamps();
            $table->index(['warehouse_code', 'status', 'source_generated_at'], 'stock_batch_current_index');
        });

        Schema::create('stock_snapshot_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('stock_snapshot_batch_id')->constrained()->restrictOnDelete();
            $table->unsignedInteger('item_index');
            $table->string('product_reference', 128)->nullable()->index();
            $table->foreignId('product_configuration_id')->nullable()->constrained('legacy_product_configurations')->nullOnDelete();
            $table->integer('available_quantity')->nullable();
            $table->integer('reserved_quantity')->nullable();
            $table->string('source_row_version', 128)->nullable();
            $table->char('source_fingerprint', 64);
            $table->boolean('accepted')->default(false)->index();
            $table->string('rejection_code', 48)->nullable()->index();
            $table->timestamp('created_at')->useCurrent();
            $table->unique(['stock_snapshot_batch_id', 'item_index'], 'stock_snapshot_item_position_unique');
            $table->index(['product_reference', 'accepted', 'stock_snapshot_batch_id'], 'stock_snapshot_current_item_index');
        });

        Schema::create('bridge_heartbeats', function (Blueprint $table): void {
            $table->id();
            $table->uuid('event_id')->unique();
            $table->string('key_id', 64);
            $table->string('connector_id', 64)->index();
            $table->string('connector_version', 32);
            $table->timestamp('occurred_at')->index();
            $table->timestamp('last_pohoda_read_at')->nullable();
            $table->timestamp('last_snapshot_at')->nullable();
            $table->timestamp('last_command_poll_at')->nullable();
            $table->string('status', 16)->index();
            $table->char('payload_hash', 64);
            $table->timestamp('received_at')->useCurrent();
        });

        Schema::create('bridge_commands', function (Blueprint $table): void {
            $table->id();
            $table->uuid('command_id')->unique();
            $table->string('type', 64)->index();
            $table->json('payload');
            $table->string('status', 16)->default('PENDING')->index();
            $table->unsignedInteger('attempts')->default(0);
            $table->timestamp('available_at')->useCurrent()->index();
            $table->timestamp('lease_until')->nullable()->index();
            $table->uuid('lease_token')->nullable()->unique();
            $table->string('claimed_by', 64)->nullable();
            $table->string('last_error_code', 64)->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
            $table->index(['status', 'available_at', 'lease_until', 'id'], 'bridge_command_claim_index');
        });

        Schema::create('bridge_command_results', function (Blueprint $table): void {
            $table->id();
            $table->uuid('result_id')->unique();
            $table->foreignId('bridge_command_id')->constrained()->restrictOnDelete();
            $table->string('status', 16);
            $table->string('external_code', 64)->nullable();
            $table->json('metadata')->nullable();
            $table->timestamp('completed_at');
            $table->timestamp('received_at')->useCurrent();
            $table->index(['bridge_command_id', 'received_at'], 'bridge_command_result_history_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bridge_command_results');
        Schema::dropIfExists('bridge_commands');
        Schema::dropIfExists('bridge_heartbeats');
        Schema::dropIfExists('stock_snapshot_items');
        Schema::dropIfExists('stock_snapshot_batches');
        Schema::dropIfExists('bridge_nonces');
    }
};
