<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        User::query()->create([
            'name' => 'Ana Torres',
            'email' => 'ana.torres@example.com',
        ]);

        User::query()->create([
            'name' => 'Luis Ramirez',
            'email' => 'luis.ramirez@example.com',
        ]);
    }
}
