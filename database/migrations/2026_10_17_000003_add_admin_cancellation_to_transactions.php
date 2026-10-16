<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        foreach (['service_bookings', 'marketplace_orders'] as $name) {
            Schema::table($name, function (Blueprint $table) {
                $table->foreignId('cancelled_by_admin_id')
                    ->nullable()
                    ->constrained('users')
                    ->nullOnDelete();

                $table->text('admin_cancellation_reason')->nullable();
            });
        }
    }

    public function down(): void
    {
        foreach (['service_bookings', 'marketplace_orders'] as $name) {
            Schema::table($name, function (Blueprint $table) {
                $table->dropConstrainedForeignId('cancelled_by_admin_id');
                $table->dropColumn('admin_cancellation_reason');
            });
        }
    }
};