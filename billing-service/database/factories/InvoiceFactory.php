<?php

namespace Database\Factories;

use App\Models\Invoice;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Invoice>
 */
class InvoiceFactory extends Factory
{
    public function definition(): array
    {
        return [
            'user_id' => $this->faker->randomDigitNotNull() + 1,
            'event_id' => $this->faker->uuid(),
            'task_id' => $this->faker->numberBetween(1, 100),
            'task_title' => $this->faker->sentence(3),
            'amount' => 10.00,
            'status' => Invoice::STATUS_PENDING,
        ];
    }
}
