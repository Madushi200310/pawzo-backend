<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pet_rentals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('pet_name', 120);
            $table->string('pet_type', 25);
            $table->string('breed', 100)->nullable();
            $table->string('gender', 20);
            $table->string('age', 50)->nullable();
            $table->decimal('price_per_day', 10, 2);
            $table->string('location_name', 255);
            $table->decimal('latitude', 10, 8);
            $table->decimal('longitude', 11, 8);
            $table->text('description');
            $table->dateTimeTz('available_from');
            $table->dateTimeTz('available_to')->nullable();
            $table->string('status', 20)->default('available');
            $table->timestampsTz();

            $table->index(['user_id', 'status', 'created_at']);
            $table->index(['pet_type', 'status', 'created_at']);
            $table->index(['status', 'available_from']);
        });

        Schema::create('pet_rental_photos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('pet_rental_id')->constrained()->cascadeOnDelete();
            $table->string('path', 500);
            $table->timestampsTz();

            $table->index('pet_rental_id');
        });

        Schema::create('pet_rental_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('pet_rental_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->date('start_date');
            $table->date('end_date');
            $table->text('message')->nullable();
            $table->string('status', 20)->default('pending');
            $table->timestampsTz();

            $table->index(['pet_rental_id', 'status']);
            $table->index(['user_id', 'status']);
            $table->index(['pet_rental_id', 'start_date', 'end_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pet_rental_requests');
        Schema::dropIfExists('pet_rental_photos');
        Schema::dropIfExists('pet_rentals');
    }
};
