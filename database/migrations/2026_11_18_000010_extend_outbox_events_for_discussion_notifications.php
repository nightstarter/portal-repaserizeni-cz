<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('outbox_events', function (Blueprint $table): void {
            $table->foreignId('recipient_user_id')->nullable()->after('aggregate_id')->constrained('users')->restrictOnDelete();
            $table->timestamp('next_attempt_at')->nullable()->after('available_at')->index();
            $table->timestamp('locked_at')->nullable()->after('next_attempt_at')->index();
            $table->uuid('lock_token')->nullable()->after('locked_at')->unique();
            $table->timestamp('sent_at')->nullable()->after('processed_at')->index();
            $table->timestamp('failed_at')->nullable()->after('sent_at')->index();

            $table->index(['status', 'next_attempt_at'], 'outbox_status_next_attempt_index');
            $table->index(['recipient_user_id', 'event_type'], 'outbox_recipient_type_index');
        });
    }

    public function down(): void
    {
        Schema::table('outbox_events', function (Blueprint $table): void {
            $table->dropIndex('outbox_status_next_attempt_index');
            $table->dropIndex('outbox_recipient_type_index');
            $table->dropUnique(['lock_token']);
            $table->dropIndex(['locked_at']);
            $table->dropIndex(['next_attempt_at']);
            $table->dropIndex(['sent_at']);
            $table->dropIndex(['failed_at']);
            $table->dropConstrainedForeignId('recipient_user_id');
            $table->dropColumn(['next_attempt_at', 'locked_at', 'lock_token', 'sent_at', 'failed_at']);
        });
    }
};
