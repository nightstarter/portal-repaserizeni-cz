<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('monitor_runs', function (Blueprint $table): void {
            $table->id();
            $table->uuid('run_uuid')->unique();
            $table->string('status', 16)->index();
            $table->string('requested_check', 80)->nullable();
            $table->boolean('notifications_suppressed')->default(false);
            $table->boolean('lock_contended')->default(false);
            $table->unsignedSmallInteger('ok_count')->default(0);
            $table->unsignedSmallInteger('info_count')->default(0);
            $table->unsignedSmallInteger('warn_count')->default(0);
            $table->unsignedSmallInteger('error_count')->default(0);
            $table->unsignedSmallInteger('critical_count')->default(0);
            $table->json('safe_results')->nullable();
            $table->string('failure_code', 80)->nullable();
            $table->timestamp('started_at')->index();
            $table->timestamp('completed_at')->nullable()->index();
            $table->timestamps();
        });

        Schema::create('operational_alerts', function (Blueprint $table): void {
            $table->id();
            $table->string('check_key', 80)->index();
            $table->string('severity', 16)->index();
            $table->string('lifecycle', 20)->index();
            $table->string('fingerprint', 64);
            $table->string('active_dedup_key', 145)->nullable()->unique();
            $table->string('summary', 500);
            $table->json('safe_metadata')->nullable();
            $table->unsignedInteger('occurrence_count')->default(1);
            $table->timestamp('first_seen_at');
            $table->timestamp('last_seen_at')->index();
            $table->foreignId('acknowledged_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('acknowledged_at')->nullable();
            $table->string('acknowledgement_note', 500)->nullable();
            $table->timestamp('resolved_at')->nullable()->index();
            $table->unsignedInteger('row_version')->default(1);
            $table->timestamp('last_notified_at')->nullable();
            $table->string('last_notified_state', 30)->nullable();
            $table->timestamps();
            $table->index(['check_key', 'fingerprint', 'lifecycle'], 'ops_alert_lookup');
        });

        Schema::create('operational_alert_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('operational_alert_id')->constrained()->cascadeOnDelete();
            $table->foreignId('monitor_run_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('actor_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('event_type', 30)->index();
            $table->string('severity', 16);
            $table->string('fingerprint', 64);
            $table->string('summary', 500);
            $table->json('safe_metadata')->nullable();
            $table->string('note', 500)->nullable();
            $table->timestamp('created_at')->useCurrent()->index();
        });

        Schema::create('scheduler_heartbeats', function (Blueprint $table): void {
            $table->string('key', 40)->primary();
            $table->timestamp('scheduler_seen_at')->nullable()->index();
            $table->boolean('monitor_enabled')->default(false);
            $table->foreignId('last_monitor_run_id')->nullable()->constrained('monitor_runs')->nullOnDelete();
            $table->string('last_monitor_status', 16)->nullable();
            $table->timestamp('updated_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('scheduler_heartbeats');
        Schema::dropIfExists('operational_alert_events');
        Schema::dropIfExists('operational_alerts');
        Schema::dropIfExists('monitor_runs');
    }
};
