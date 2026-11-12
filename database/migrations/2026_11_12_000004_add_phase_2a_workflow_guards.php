<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('thread_events', function (Blueprint $table): void {
            $table->string('idempotency_key', 128)->nullable()->after('action');
            $table->char('request_hash', 64)->nullable()->after('idempotency_key');
            $table->unique(['process_thread_id', 'idempotency_key']);
        });
    }

    public function down(): void
    {
        Schema::table('thread_events', function (Blueprint $table): void {
            $table->dropUnique(['process_thread_id', 'idempotency_key']);
            $table->dropColumn(['idempotency_key', 'request_hash']);
        });
    }
};
