<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pet_sale_listings', function (Blueprint $table) {
            $table->id();

            // Seller
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();

            // Pet info
            $table->string('pet_type');                     // Dog, Cat, Bird, etc.
            $table->string('breed')->nullable();
            $table->string('age')->nullable();              // "2 years", "6 months"
            $table->enum('gender', ['male', 'female', 'unknown'])->default('unknown');
            $table->string('color')->nullable();
            $table->text('description')->nullable();

            // Location
            $table->string('location')->nullable();         // free text
            $table->string('city')->nullable();
            $table->string('district')->nullable();

            // Pricing
            $table->decimal('price', 10, 2);
            $table->boolean('is_negotiable')->default(false);

            // Media
            $table->json('photos')->nullable();
            $table->json('certificates')->nullable();

            // Contact
            $table->string('contact_phone')->nullable();
            $table->string('contact_whatsapp')->nullable();

            // Status
            $table->enum('status', ['available', 'reserved', 'sold'])->default('available');
            $table->boolean('is_approved')->default(false);
            $table->boolean('is_active')->default(true);
            $table->text('rejection_reason')->nullable();

            $table->timestamps();

            // Indexes
            $table->index(['user_id', 'is_approved']);
            $table->index(['pet_type', 'is_approved']);
            $table->index(['status', 'is_approved']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pet_sale_listings');
    }
};