<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('messages', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_token')->unique();
            $table->foreignId('order_id')->constrained()->restrictOnDelete();
            $table->foreignId('author_user_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('author_organization_id')->constrained('organizations')->restrictOnDelete();
            $table->text('body');
            $table->string('idempotency_key', 128)->unique();
            $table->char('request_hash', 64);
            $table->timestamp('created_at')->useCurrent();

            $table->index(['order_id', 'created_at']);
        });

        Schema::create('message_attachments', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_token')->unique();
            $table->foreignId('message_id')->constrained()->restrictOnDelete();
            $table->string('storage_disk', 32);
            $table->string('storage_path', 255)->unique();
            $table->string('original_name', 255);
            $table->string('mime', 100);
            $table->string('extension', 8);
            $table->unsignedBigInteger('size');
            $table->char('checksum_sha256', 64);
            $table->timestamp('created_at')->useCurrent();
        });

        Schema::create('message_reads', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('message_id')->constrained()->restrictOnDelete();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->timestamp('read_at');

            $table->unique(['message_id', 'user_id']);
            $table->index(['user_id', 'read_at']);
        });

        Schema::create('outbox_events', function (Blueprint $table): void {
            $table->id();
            $table->uuid('event_uuid')->unique();
            $table->string('event_type', 100)->index();
            $table->string('aggregate_type', 100);
            $table->unsignedBigInteger('aggregate_id');
            $table->string('deduplication_key', 160)->unique();
            $table->json('payload');
            $table->string('status', 24)->default('PENDING')->index();
            $table->unsignedInteger('attempts')->default(0);
            $table->timestamp('available_at')->useCurrent()->index();
            $table->timestamp('processed_at')->nullable();
            $table->text('last_error')->nullable();
            $table->timestamps();

            $table->index(['aggregate_type', 'aggregate_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('outbox_events');
        Schema::dropIfExists('message_reads');
        Schema::dropIfExists('message_attachments');
        Schema::dropIfExists('messages');
    }
};
