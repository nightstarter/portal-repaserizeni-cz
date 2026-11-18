<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('documents', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_token')->unique();
            $table->foreignId('order_id')->constrained()->restrictOnDelete();
            $table->foreignId('process_thread_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('type', 48)->index();
            $table->string('storage_disk', 32);
            $table->string('storage_name', 255)->unique();
            $table->string('original_name', 255);
            $table->string('mime', 100);
            $table->string('extension', 8);
            $table->unsignedBigInteger('size');
            $table->char('checksum_sha256', 64);
            $table->string('document_number', 128);
            $table->date('document_date');
            $table->decimal('amount', 18, 2);
            $table->char('currency', 3);
            $table->foreignId('uploaded_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('uploaded_organization_id')->constrained('organizations')->restrictOnDelete();
            $table->string('status', 24)->index();
            $table->string('active_key', 160)->nullable()->unique();
            $table->foreignId('replaces_document_id')->nullable()->constrained('documents')->restrictOnDelete();
            $table->text('invalidation_reason')->nullable();
            $table->timestamp('invalidated_at')->nullable();
            $table->foreignId('invalidated_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamps();

            $table->index(['order_id', 'type', 'status']);
            $table->index(['process_thread_id', 'type', 'status']);
        });

        Schema::create('document_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('document_id')->constrained()->restrictOnDelete();
            $table->foreignId('order_id')->constrained()->restrictOnDelete();
            $table->string('action', 64);
            $table->string('idempotency_key', 128)->unique();
            $table->char('request_hash', 64);
            $table->foreignId('actor_user_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('actor_organization_id')->constrained('organizations')->restrictOnDelete();
            $table->text('reason')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamp('performed_at')->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('document_events');
        Schema::dropIfExists('documents');
    }
};
