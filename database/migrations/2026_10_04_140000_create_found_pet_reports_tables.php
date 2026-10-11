<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('found_pet_reports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();

            $table->string('pet_name', 100)->nullable();
            $table->string('pet_type', 30);
            $table->string('breed', 100)->nullable();
            $table->string('gender', 10);
            $table->string('color', 100);
            $table->string('age_description', 100)->nullable();
            $table->text('distinguishing_features')->nullable();
            $table->text('description');

            $table->timestampTz('found_at');
            $table->string('location_name');
            $table->decimal('latitude', 10, 7);
            $table->decimal('longitude', 10, 7);

            $table->string('contact_name', 100);
            $table->string('contact_phone', 20);
            $table->string('whatsapp_number', 20);

            $table->string('status', 20)->default('open');
            $table->timestampTz('resolved_at')->nullable();
            $table->timestampsTz();

            $table->index(['status', 'pet_type']);
            $table->index(['user_id', 'created_at']);
            $table->index('found_at');
        });

        Schema::create('found_pet_photos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('found_pet_report_id')
                ->constrained()
                ->cascadeOnDelete();
            $table->string('path');
            $table->timestampsTz();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('found_pet_photos');
        Schema::dropIfExists('found_pet_reports');
    }
};