<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('orders', function (Blueprint $table): void {
            $table->id();
            $table->string('order_no', 64)->unique();
            $table->string('source', 32);
            $table->string('source_order_id', 64)->nullable();
            $table->string('order_type', 32)->index();
            $table->string('technical_type', 32)->nullable();
            $table->string('routing', 16)->nullable();
            $table->string('product_code', 64)->index();
            $table->string('product_name_snapshot')->nullable();
            $table->string('vin', 32)->nullable()->index();
            $table->string('vehicle')->nullable();
            $table->string('customer_handover', 32)->nullable();
            $table->string('product_verification_status', 32)->default('UNVERIFIED');
            $table->json('business_snapshot')->nullable();
            $table->timestamp('imported_at')->nullable();
            $table->timestamp('closed_at')->nullable()->index();
            $table->timestamps();

            $table->unique(['source', 'source_order_id']);
        });

        Schema::create('order_customers', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('order_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('company')->nullable();
            $table->string('email')->nullable();
            $table->string('phone', 64)->nullable();
            $table->string('street')->nullable();
            $table->string('city')->nullable();
            $table->string('postal_code', 32)->nullable();
            $table->char('country_code', 2)->nullable();
            $table->timestamps();
        });

        Schema::create('items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();
            $table->string('role', 32)->index();
            $table->string('qr_token', 64)->unique();
            $table->string('current_location', 32)->index();
            $table->string('inspection_result', 32)->nullable();
            $table->json('technical_attributes')->nullable();
            $table->text('note')->nullable();
            $table->timestamps();
        });

        Schema::create('process_threads', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();
            $table->string('type', 32)->index();
            $table->string('state', 64)->index();
            $table->string('responsible_party', 32)->index();
            $table->foreignId('responsible_organization_id')->nullable()->constrained('organizations')->restrictOnDelete();
            $table->string('vendor_routing', 16)->nullable();
            $table->unsignedInteger('warranty_cycle')->nullable();
            $table->timestamp('eta')->nullable();
            $table->timestamp('completed_at')->nullable()->index();
            $table->unsignedInteger('lock_version')->default(0);
            $table->timestamps();

            $table->index(['order_id', 'type']);
        });

        Schema::create('thread_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('process_thread_id')->constrained()->cascadeOnDelete();
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();
            $table->string('action', 80)->index();
            $table->string('from_state', 64)->nullable();
            $table->string('to_state', 64);
            $table->string('actor_type', 32)->default('SYSTEM');
            $table->foreignId('actor_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('actor_organization_id')->nullable()->constrained('organizations')->nullOnDelete();
            $table->foreignId('responsible_organization_id')->nullable()->constrained('organizations')->nullOnDelete();
            $table->text('reason')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamp('performed_at')->useCurrent()->index();
        });

        Schema::create('integration_events', function (Blueprint $table): void {
            $table->id();
            $table->string('integration', 64);
            $table->string('external_event_id', 128);
            $table->string('type', 80)->index();
            $table->char('payload_hash', 64);
            $table->json('payload')->nullable();
            $table->string('payload_reference')->nullable();
            $table->string('status', 32)->default('PENDING')->index();
            $table->unsignedInteger('attempts')->default(0);
            $table->text('error')->nullable();
            $table->timestamp('processed_at')->nullable();
            $table->timestamps();

            $table->unique(['integration', 'external_event_id']);
        });

        Schema::create('integration_checkpoints', function (Blueprint $table): void {
            $table->id();
            $table->string('integration', 64)->unique();
            $table->text('cursor')->nullable();
            $table->string('last_processed_id', 128)->nullable();
            $table->timestamp('last_processed_at')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('integration_checkpoints');
        Schema::dropIfExists('integration_events');
        Schema::dropIfExists('thread_events');
        Schema::dropIfExists('process_threads');
        Schema::dropIfExists('items');
        Schema::dropIfExists('order_customers');
        Schema::dropIfExists('orders');
    }
};
