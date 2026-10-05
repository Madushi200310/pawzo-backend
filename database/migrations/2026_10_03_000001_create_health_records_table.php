<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('health_records', function (Blueprint $table) {
            $table->id();
            $table->foreignId('pet_id')->constrained()->cascadeOnDelete();
            $table->enum('record_type', [
                'medical_history',
                'vet_visit',
                'treatment',
                'disease',
                'medication',
            ]);
            $table->string('title');
            $table->text('description')->nullable();
            $table->date('date')->nullable();

            // Vet-visit fields
            $table->string('vet_name')->nullable();
            $table->string('clinic_name')->nullable();

            // Medication fields
            $table->string('medication_name')->nullable();
            $table->string('dosage')->nullable();
            $table->date('start_date')->nullable();
            $table->date('end_date')->nullable();

            // Common
            $table->boolean('is_ongoing')->default(false);

            $table->timestamps();
            $table->softDeletes();

            $table->index(['pet_id', 'record_type']);
            $table->index(['pet_id', 'date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('health_records');
    }
};