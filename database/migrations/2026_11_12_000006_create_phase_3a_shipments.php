<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shipments', function (Blueprint $table): void {
            $table->id();
            $table->string('public_number', 40)->unique();
            $table->string('direction', 16)->index();
            $table->string('carrier', 32);
            $table->string('tracking', 128)->nullable();
            $table->string('status', 32)->index();
            $table->foreignId('sender_organization_id')->constrained('organizations')->restrictOnDelete();
            $table->foreignId('recipient_organization_id')->constrained('organizations')->restrictOnDelete();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->text('transport_error_text')->nullable();
            $table->unsignedInteger('lock_version')->default(0);
            $table->timestamps();

            $table->unique(['carrier', 'tracking']);
        });

        Schema::create('shipment_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('shipment_id')->constrained()->restrictOnDelete();
            $table->foreignId('item_id')->constrained()->restrictOnDelete();
            $table->foreignId('process_thread_id')->constrained()->restrictOnDelete();
            $table->timestamps();

            $table->unique(['shipment_id', 'item_id']);
            $table->index(['process_thread_id', 'shipment_id']);
        });

        Schema::create('shipment_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('shipment_id')->constrained()->restrictOnDelete();
            $table->string('action', 80)->index();
            $table->string('idempotency_key', 128)->unique();
            $table->char('request_hash', 64);
            $table->string('from_status', 32)->nullable();
            $table->string('to_status', 32);
            $table->foreignId('actor_user_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('actor_organization_id')->constrained('organizations')->restrictOnDelete();
            $table->text('reason')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamp('performed_at')->useCurrent()->index();
        });

        Schema::table('items', function (Blueprint $table): void {
            $table->foreignId('active_shipment_id')
                ->nullable()
                ->after('role_instance')
                ->constrained('shipments')
                ->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('items', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('active_shipment_id');
        });
        Schema::dropIfExists('shipment_events');
        Schema::dropIfExists('shipment_items');
        Schema::dropIfExists('shipments');
    }
};
