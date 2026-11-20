<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stock_snapshot_runs', function (Blueprint $table): void {
            $table->id();
            $table->uuid('run_id')->unique();
            $table->string('warehouse_code', 32);
            $table->timestamp('source_generated_at')->index();
            $table->unsignedInteger('chunk_count');
            $table->unsignedInteger('received_chunks')->default(0);
            $table->unsignedInteger('accepted_count')->default(0);
            $table->unsignedInteger('rejected_count')->default(0);
            $table->string('status', 16)->default('RECEIVING')->index();
            $table->timestamp('expires_at')->index();
            $table->timestamp('completed_at')->nullable()->index();
            $table->timestamps();
            $table->index(['warehouse_code', 'status', 'source_generated_at'], 'stock_run_current_index');
        });

        Schema::table('stock_snapshot_batches', function (Blueprint $table): void {
            $table->foreignId('stock_snapshot_run_id')->nullable()->after('id')
                ->constrained('stock_snapshot_runs')->restrictOnDelete();
            $table->unsignedInteger('chunk_index')->nullable()->after('batch_id');
            $table->unsignedInteger('chunk_count')->nullable()->after('chunk_index');
            $table->boolean('final_chunk')->default(false)->after('chunk_count');
            $table->unique(['stock_snapshot_run_id', 'chunk_index'], 'stock_batch_run_chunk_unique');
        });

        Schema::table('bridge_heartbeats', function (Blueprint $table): void {
            $table->json('error_codes')->nullable()->after('status');
        });
    }

    public function down(): void
    {
        Schema::table('bridge_heartbeats', function (Blueprint $table): void {
            $table->dropColumn('error_codes');
        });
        Schema::table('stock_snapshot_batches', function (Blueprint $table): void {
            $table->dropUnique('stock_batch_run_chunk_unique');
            $table->dropConstrainedForeignId('stock_snapshot_run_id');
            $table->dropColumn(['chunk_index', 'chunk_count', 'final_chunk']);
        });
        Schema::dropIfExists('stock_snapshot_runs');
    }
};
