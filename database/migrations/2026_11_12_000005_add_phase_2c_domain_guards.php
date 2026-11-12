<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('process_threads', function (Blueprint $table): void {
            $table->unsignedInteger('type_instance')->default(1)->after('type');
            $table->unique(['order_id', 'type', 'type_instance']);
        });

        Schema::table('items', function (Blueprint $table): void {
            $table->unsignedInteger('role_instance')->default(1)->after('role');
            $table->unique(['order_id', 'role', 'role_instance']);
        });
    }

    public function down(): void
    {
        Schema::table('items', function (Blueprint $table): void {
            $table->dropUnique(['order_id', 'role', 'role_instance']);
            $table->dropColumn('role_instance');
        });

        Schema::table('process_threads', function (Blueprint $table): void {
            $table->dropUnique(['order_id', 'type', 'type_instance']);
            $table->dropColumn('type_instance');
        });
    }
};
