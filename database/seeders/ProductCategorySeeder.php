<?php

namespace Database\Seeders;

use App\Models\ProductCategory;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class ProductCategorySeeder extends Seeder
{
    public function run(): void
    {
        $categories = [
            ['name' => 'Pet Food',   'icon' => 'fa-utensils',     'sort_order' => 1],
            ['name' => 'Treats',     'icon' => 'fa-bone',         'sort_order' => 2],
            ['name' => 'Toys',       'icon' => 'fa-baseball-ball','sort_order' => 3],
            ['name' => 'Collars',    'icon' => 'fa-circle',       'sort_order' => 4],
            ['name' => 'Leashes',    'icon' => 'fa-link',         'sort_order' => 5],
            ['name' => 'Clothes',    'icon' => 'fa-tshirt',       'sort_order' => 6],
            ['name' => 'Accessories','icon' => 'fa-gem',          'sort_order' => 7],
            ['name' => 'Health Care','icon' => 'fa-heartbeat',    'sort_order' => 8],
        ];

        foreach ($categories as $cat) {
            ProductCategory::updateOrCreate(
                ['slug' => Str::slug($cat['name'])],
                [
                    'name'       => $cat['name'],
                    'slug'       => Str::slug($cat['name']),
                    'icon'       => $cat['icon'],
                    'is_active'  => true,
                    'sort_order' => $cat['sort_order'],
                ]
            );
        }

        $this->command->info('✅ ' . count($categories) . ' product categories seeded.');
    }
}