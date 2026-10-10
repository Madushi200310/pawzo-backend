<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reviews', function (Blueprint $table) {
            $table->id();

            // Author
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();

            // Polymorphic target (Product, Business, PetSaleListing)
            $table->string('reviewable_type');
            $table->unsignedBigInteger('reviewable_id');

            // Rating 1-5
            $table->unsignedTinyInteger('rating');

            // Comment
            $table->text('comment')->nullable();

            // Moderation
            $table->boolean('is_approved')->default(false);
            $table->text('rejection_reason')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();

            // Constraints
            $table->unique(['user_id', 'reviewable_type', 'reviewable_id'], 'unique_user_review');
            $table->index(['reviewable_type', 'reviewable_id', 'is_approved'], 'idx_reviewable_approved');
            $table->index('rating');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reviews');
    }
};