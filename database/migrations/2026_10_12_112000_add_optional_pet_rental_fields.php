<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pet_rentals', function (Blueprint $table) {
            $table->string('color', 100)->nullable()->after('age');
            $table->string('age_description', 100)->nullable()->after('color');
            $table->text('characteristics')->nullable()->after('age_description');
            $table->string('purpose', 200)->nullable()->after('characteristics');
            $table->string('contact_phone', 20)->nullable()->after('purpose');
        });

        Schema::table('pet_rentals', function (Blueprint $table) {
            $table->date('available_from')->change();
            $table->date('available_to')->nullable(false)->change();
        });
    }

    public function down(): void
    {
        Schema::table('pet_rentals', function (Blueprint $table) {
            $table->dropColumn(['color', 'age_description', 'characteristics', 'purpose', 'contact_phone']);
        });

        Schema::table('pet_rentals', function (Blueprint $table) {
            $table->dateTimeTz('available_from')->change();
            $table->dateTimeTz('available_to')->nullable()->change();
        });
    }
};
