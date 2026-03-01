<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class StationsSeeder extends Seeder
{
    public function run(): void
    {
        DB::table('stations')->insert([
            ['name' => 'Place des Martyrs', 'code' => 'ALG01', 'line' => 'Line 1', 'lat' => 36.7858, 'lng' => 3.0601],
            ['name' => 'Tafourah - Grande Poste', 'code' => 'ALG02', 'line' => 'Line 1', 'lat' => 36.7762, 'lng' => 3.0583],
            ['name' => 'Khelifa Boukhalfa', 'code' => 'ALG03', 'line' => 'Line 1', 'lat' => 36.7729, 'lng' => 3.0585],
            // add more…
        ]);
    }
}
