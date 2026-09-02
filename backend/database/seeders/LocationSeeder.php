<?php

namespace Database\Seeders;

use App\Models\Location;
use Illuminate\Database\Seeder;

class LocationSeeder extends Seeder
{
    public function run(): void
    {
        $locations = [
            [
                'country_code' => 'TH',
                'country_name' => 'Thailand',
                'city' => 'Bangkok',
                'display_name' => 'Bangkok, Thailand',
                'active' => true,
                'sort_order' => 10,
            ],
            [
                'country_code' => 'SG',
                'country_name' => 'Singapore',
                'city' => 'Singapore',
                'display_name' => 'Singapore',
                'active' => true,
                'sort_order' => 20,
            ],
            [
                'country_code' => 'JP',
                'country_name' => 'Japan',
                'city' => 'Tokyo',
                'display_name' => 'Tokyo, Japan',
                'active' => true,
                'sort_order' => 30,
            ],
        ];

        foreach ($locations as $locationData) {
            Location::query()->updateOrCreate(
                ['display_name' => $locationData['display_name']],
                $locationData,
            );
        }
    }
}
