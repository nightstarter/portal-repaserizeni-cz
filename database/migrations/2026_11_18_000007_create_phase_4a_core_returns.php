<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('core_return_inspections', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('order_id')->constrained()->restrictOnDelete();
            $table->foreignId('process_thread_id')->constrained()->restrictOnDelete();
            $table->foreignId('item_id')->constrained()->restrictOnDelete();
            $table->string('piece_result', 32);
            $table->string('transport_condition', 64);
            $table->boolean('accident');
            $table->boolean('disassembled');
            $table->boolean('corrosion');
            $table->boolean('axial_rods');
            $table->text('note')->nullable();
            $table->foreignId('actor_user_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('actor_organization_id')->constrained('organizations')->restrictOnDelete();
            $table->timestamp('inspected_at')->useCurrent();

            $table->index(['process_thread_id', 'inspected_at']);
        });

        Schema::create('finance_entries', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('order_id')->constrained()->restrictOnDelete();
            $table->foreignId('process_thread_id')->constrained()->restrictOnDelete();
            $table->foreignId('thread_event_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('audit_log_id')->constrained('audit_log')->restrictOnDelete();
            $table->string('type', 64)->index();
            $table->decimal('amount', 18, 2);
            $table->char('currency', 3);
            $table->string('status', 32);
            $table->foreignId('actor_user_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('actor_organization_id')->constrained('organizations')->restrictOnDelete();
            $table->text('reason')->nullable();
            $table->string('idempotency_key', 128)->unique();
            $table->char('request_hash', 64);
            $table->foreignId('corrects_entry_id')->nullable()->constrained('finance_entries')->restrictOnDelete();
            $table->timestamp('recorded_at')->useCurrent()->index();

            $table->index(['process_thread_id', 'type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('finance_entries');
        Schema::dropIfExists('core_return_inspections');
    }
};
