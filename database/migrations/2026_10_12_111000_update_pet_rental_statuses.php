<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pet_rentals', function (Blueprint $table) {
            $table->string('status', 20)->default('active')->change();
        });

        DB::table('pet_rentals')
            ->whereIn('status', ['available', 'reserved'])
            ->update(['status' => 'active']);
    }

    public function down(): void
    {
        DB::table('pet_rentals')
            ->where('status', 'active')
            ->update(['status' => 'available']);

        Schema::table('pet_rentals', function (Blueprint $table) {
            $table->string('status', 20)->default('available')->change();
        });
    }
};
