<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('process_threads', function (Blueprint $table): void {
            $table->foreignId('item_id')->nullable()->after('order_id')->constrained('items')->restrictOnDelete();
            $table->foreignId('created_by')->nullable()->after('warranty_cycle')->constrained('users')->restrictOnDelete();
            $table->string('creation_idempotency_key', 128)->nullable()->unique()->after('created_by');
            $table->char('creation_request_hash', 64)->nullable()->after('creation_idempotency_key');
            $table->unique(['order_id', 'type', 'item_id', 'warranty_cycle'], 'threads_warranty_cycle_unique');
            $table->index(['type', 'item_id', 'completed_at'], 'threads_warranty_active_index');
        });

        Schema::table('items', function (Blueprint $table): void {
            $table->foreignId('active_warranty_thread_id')
                ->nullable()
                ->after('active_shipment_id')
                ->unique()
                ->constrained('process_threads')
                ->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('items', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('active_warranty_thread_id');
        });
        Schema::table('process_threads', function (Blueprint $table): void {
            $table->dropIndex('threads_warranty_active_index');
            $table->dropUnique('threads_warranty_cycle_unique');
            $table->dropUnique(['creation_idempotency_key']);
            $table->dropColumn(['creation_request_hash', 'creation_idempotency_key']);
            $table->dropConstrainedForeignId('created_by');
            $table->dropConstrainedForeignId('item_id');
        });
    }
};
