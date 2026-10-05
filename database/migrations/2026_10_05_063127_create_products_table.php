<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('products', function (Blueprint $table) {
            $table->id();

            // Seller
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();

            // Category
            $table->foreignId('product_category_id')->constrained()->cascadeOnDelete();

            // Product info
            $table->string('name');
            $table->string('slug')->unique();
            $table->text('description')->nullable();
            $table->decimal('price', 10, 2);
            $table->integer('quantity')->default(1);
            $table->string('brand')->nullable();
            $table->string('sku')->nullable();

            // Photos (JSON array of paths)
            $table->json('photos')->nullable();

            // Status
            $table->boolean('is_approved')->default(false);
            $table->boolean('is_active')->default(true);
            $table->text('rejection_reason')->nullable();

            $table->timestamps();

            $table->index(['user_id', 'is_approved']);
            $table->index(['product_category_id', 'is_approved']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('products');
    }
};