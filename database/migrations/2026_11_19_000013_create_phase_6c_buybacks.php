<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('buyback_order_sequences', function (Blueprint $table): void {
            $table->unsignedSmallInteger('year')->primary();
            $table->unsignedBigInteger('last_number')->default(0);
            $table->timestamps();
        });

        Schema::table('orders', function (Blueprint $table): void {
            $table->string('creation_idempotency_key', 128)->nullable()->unique()->after('source_order_id');
            $table->char('creation_request_hash', 64)->nullable()->after('creation_idempotency_key');
        });

        Schema::create('buyback_price_quotes', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('order_id')->constrained()->restrictOnDelete();
            $table->foreignId('process_thread_id')->constrained()->restrictOnDelete();
            $table->string('type', 40);
            $table->unsignedBigInteger('amount_minor');
            $table->char('currency', 3);
            $table->unsignedInteger('version');
            $table->foreignId('actor_user_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('actor_organization_id')->constrained('organizations')->restrictOnDelete();
            $table->string('idempotency_key', 128)->unique();
            $table->char('request_hash', 64);
            $table->timestamp('recorded_at')->useCurrent();

            $table->unique(['process_thread_id', 'type', 'version'], 'buyback_quote_version_unique');
            $table->index(['process_thread_id', 'type', 'recorded_at'], 'buyback_quote_history_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('buyback_price_quotes');
        Schema::table('orders', function (Blueprint $table): void {
            $table->dropUnique(['creation_idempotency_key']);
            $table->dropColumn(['creation_request_hash', 'creation_idempotency_key']);
        });
        Schema::dropIfExists('buyback_order_sequences');
    }
};
