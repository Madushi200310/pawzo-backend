<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('listing_categories', function (Blueprint $table) {
            $table->id();
            $table->string('group', 20);
            $table->string('value', 30);
            $table->string('name', 100);
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();

            $table->unique(['group', 'value']);
            $table->index(['group', 'is_active', 'sort_order']);
        });

        $groups = [
            'pet' => [
                'table' => 'pets',
                'column' => 'type',
                'defaults' => ['dog', 'cat', 'bird', 'rabbit', 'other'],
            ],
            'service' => [
                'table' => 'pet_services',
                'column' => 'service_type',
                'defaults' => [
                    'veterinary', 'grooming', 'boarding',
                    'sitting', 'walking', 'training',
                ],
            ],
            'rental' => [
                'table' => 'pet_rentals',
                'column' => 'pet_type',
                'defaults' => ['dog', 'cat', 'bird', 'rabbit', 'other'],
            ],
        ];

        foreach ($groups as $group => $settings) {
            $values = $settings['defaults'];

            if (Schema::hasTable($settings['table'])) {
                $existing = DB::table($settings['table'])
                    ->distinct()
                    ->pluck($settings['column'])
                    ->all();

                $values = array_merge($values, $existing);
            }

            $normalized = [];

            foreach ($values as $value) {
                $value = mb_strtolower(trim((string) $value));

                if ($value !== '' && mb_strlen($value) <= 30) {
                    $normalized[$value] = true;
                }
            }

            foreach (array_keys($normalized) as $position => $value) {
                DB::table('listing_categories')->insert([
                    'group' => $group,
                    'value' => $value,
                    'name' => mb_convert_case(
                        str_replace(['-', '_'], ' ', $value),
                        MB_CASE_TITLE,
                        'UTF-8',
                    ),
                    'is_active' => true,
                    'sort_order' => $position,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('listing_categories');
    }
};