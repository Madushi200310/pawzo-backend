<?php

namespace Database\Seeders;

use App\Models\BusinessType;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class BusinessTypeSeeder extends Seeder
{
    public function run(): void
    {
        $types = [
            ['name' => 'Hotels & Dog Boarding Owners',      'icon' => 'fa-hotel',           'sort_order' => 1],
            ['name' => 'Veterinarians / Veterinary Clinics','icon' => 'fa-stethoscope',     'sort_order' => 2],
            ['name' => 'Pet Groomers',                       'icon' => 'fa-scissors',        'sort_order' => 3],
            ['name' => 'Pet Trainers',                       'icon' => 'fa-graduation-cap',  'sort_order' => 4],
            ['name' => 'Pet Sitters',                        'icon' => 'fa-home',            'sort_order' => 5],
            ['name' => 'Pet Product Sellers',                'icon' => 'fa-shopping-bag',    'sort_order' => 6],
            ['name' => 'Pet Sellers',                        'icon' => 'fa-paw',             'sort_order' => 7],
        ];

        foreach ($types as $type) {
            BusinessType::updateOrCreate(
                ['slug' => Str::slug($type['name'])],
                [
                    'name'        => $type['name'],
                    'slug'        => Str::slug($type['name']),
                    'icon'        => $type['icon'],
                    'is_active'   => true,
                    'sort_order'  => $type['sort_order'],
                ]
            );
        }

        $this->command->info('✅ ' . count($types) . ' business types seeded.');
    }
}