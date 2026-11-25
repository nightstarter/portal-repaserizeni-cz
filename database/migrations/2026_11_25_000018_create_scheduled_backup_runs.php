<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('scheduled_backup_runs', function (Blueprint $table): void {
            $table->id();
            $table->uuid('run_uuid')->unique();
            $table->string('status', 24)->index();
            $table->string('target_name', 80)->nullable();
            $table->string('backup_id', 80)->nullable()->index();
            $table->string('failure_code', 80)->nullable();
            $table->timestamp('started_at')->index();
            $table->timestamp('completed_at')->index();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('scheduled_backup_runs');
    }
};
