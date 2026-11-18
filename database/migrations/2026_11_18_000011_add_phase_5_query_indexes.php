<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('messages', function (Blueprint $table): void {
            $table->index(['order_id', 'created_at', 'id'], 'messages_order_created_id_index');
        });
        Schema::table('outbox_events', function (Blueprint $table): void {
            $table->index(['event_type', 'status', 'next_attempt_at', 'id'], 'outbox_worker_due_index');
            $table->index(['event_type', 'status', 'locked_at', 'id'], 'outbox_worker_lock_index');
        });
    }

    public function down(): void
    {
        Schema::table('outbox_events', function (Blueprint $table): void {
            $table->dropIndex('outbox_worker_due_index');
            $table->dropIndex('outbox_worker_lock_index');
        });
        Schema::table('messages', function (Blueprint $table): void {
            $table->dropIndex('messages_order_created_id_index');
        });
    }
};
