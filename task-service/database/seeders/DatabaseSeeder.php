<?php

namespace Database\Seeders;

use App\Models\Task;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     *
     * Ojo: aquí NO se siembran usuarios. El task-service no posee datos de
     * usuarios; solo guarda el user_id que le llega por HTTP ya validado.
     */
    public function run(): void
    {
        Task::query()->create([
            'title' => 'Revisar el reporte mensual',
            'user_id' => 1,
            'status' => Task::STATUS_PENDING,
        ]);

        Task::query()->create([
            'title' => 'Actualizar dependencias del proyecto',
            'user_id' => 2,
            'status' => Task::STATUS_PENDING,
        ]);
    }
}
