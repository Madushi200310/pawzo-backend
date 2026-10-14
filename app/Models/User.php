<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, Notifiable, SoftDeletes;

    protected $fillable = [
        'name',
        'email',
        'password',
        'phone',
        'role',
        'is_active',
        'avatar',
        'google_id',
        'facebook_id',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password'          => 'hashed',
            'is_active'         => 'boolean',
            'deleted_at'        => 'datetime',
        ];
    }

    // ==================== HELPERS ====================

    public function isAdmin(): bool
    {
        return $this->role === 'admin';
    }

    // ==================== MEMBER 1 — RELATIONSHIPS ====================

    public function pets(): HasMany
    {
        return $this->hasMany(Pet::class);
    }

    public function reminders(): HasMany
    {
        return $this->hasMany(Reminder::class);
    }

    public function healthShareTokens(): HasMany
    {
        return $this->hasMany(HealthShareToken::class);
    }

    public function petReports(): HasMany
    {
        return $this->hasMany(PetReport::class, 'reporter_id');
    }

    public function reviewedPetReports(): HasMany
    {
        return $this->hasMany(PetReport::class, 'reviewed_by');
    }

    // ==================== MEMBER 1 — ADMIN HELPERS ====================

    public function petsCount(): int
    {
        return $this->pets()->withTrashed()->count();
    }

    public function healthRecordsCount(): int
    {
        return \App\Models\HealthRecord::whereIn(
            'pet_id',
            $this->pets()->withTrashed()->pluck('id')
        )->count();
    }

    public function vaccinationsCount(): int
    {
        return \App\Models\Vaccination::whereIn(
            'pet_id',
            $this->pets()->withTrashed()->pluck('id')
        )->count();
    }

    // ==================== MEMBER 3 — BUSINESS RELATIONSHIPS ====================

    public function businesses(): HasMany
    {
        return $this->hasMany(Business::class);
    }

    public function approvedBusinesses(): HasMany
    {
        return $this->hasMany(Business::class, 'approved_by');
    }

    public function products(): HasMany
    {
        return $this->hasMany(Product::class);
    }

    public function productCategories(): HasMany
    {
        return $this->hasMany(ProductCategory::class);
    }

    public function petSaleListings(): HasMany
    {
        return $this->hasMany(PetSaleListing::class);
    }

    // ==================== MEMBER 3 — REVIEWS / CHATBOT ====================

    public function reviews(): HasMany
    {
        return $this->hasMany(Review::class);
    }

    public function chatbotConversations(): HasMany
    {
        return $this->hasMany(ChatbotConversation::class);
    }
}