<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('medication_schedules', function (Blueprint $table) {
            $table->id();

            $table->foreignId('health_record_id')
                ->unique()
                ->constrained('health_records')
                ->cascadeOnDelete();

            $table->string('timezone', 100);
            $table->json('times');
            $table->boolean('is_active')->default(true);
            $table->timestampTz('next_due_at')->nullable();
            $table->timestampTz('last_notified_at')->nullable();
            $table->timestampsTz();

            $table->index(['is_active', 'next_due_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('medication_schedules');
    }
};