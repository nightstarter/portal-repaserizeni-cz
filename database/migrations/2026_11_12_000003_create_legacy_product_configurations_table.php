<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('legacy_product_configurations', function (Blueprint $table): void {
            $table->id();
            $table->string('product_reference')->unique();
            $table->string('technical_type')->nullable();
            $table->string('default_routing')->nullable();
            $table->string('verification_status')->default('UNVERIFIED')->index();
            $table->foreignId('confirmed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('confirmed_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('legacy_product_configurations');
    }
};
