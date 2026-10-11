<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Alleen bedoeld voor lokaal testen: vult de database met demogegevens.
     */
    public function run(): void
    {
        $this->call(DemoSeeder::class);
    }
}
