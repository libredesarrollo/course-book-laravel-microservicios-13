<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     *
     * El billing-service no posee datos de usuarios. Solo usa sesiones locales
     * y el token remoto.
     */
    public function run(): void
    {
        //
    }
}
