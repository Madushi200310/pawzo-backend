<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pet_services', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->string('name', 150);
            $table->string('slug', 200)->unique();
            $table->string('service_type', 30);
            $table->text('description');
            $table->string('short_description', 500)->nullable();
            $table->decimal('price_from', 10, 2);
            $table->decimal('price_to', 10, 2)->nullable();
            $table->string('currency', 3)->default('LKR');
            $table->string('location_name', 255);
            $table->decimal('latitude', 10, 7);
            $table->decimal('longitude', 10, 7);
            $table->string('contact_phone', 20);
            $table->string('contact_email', 150)->nullable();
            $table->json('opening_hours')->nullable();
            $table->string('status', 20)->default('pending');
            $table->text('rejection_reason')->nullable();
            $table->timestampTz('approved_at')->nullable();
            $table->timestampTz('removed_at')->nullable();
            $table->timestampsTz();

            $table->index(['status', 'service_type']);
            $table->index(['user_id', 'status', 'created_at']);
            $table->index(['location_name']);
            $table->index(['price_from', 'price_to']);
        });

        Schema::create('pet_service_photos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('pet_service_id')->constrained()->cascadeOnDelete();
            $table->string('path', 500);
            $table->timestampsTz();

            $table->index('pet_service_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pet_service_photos');
        Schema::dropIfExists('pet_services');
    }
};