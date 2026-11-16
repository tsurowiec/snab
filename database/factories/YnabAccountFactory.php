<?php

namespace Database\Factories;

use App\Models\User;
use App\Models\YnabAccount;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<YnabAccount>
 */
class YnabAccountFactory extends Factory
{
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'ynab_id' => fake()->uuid(),
            'name' => fake()->words(2, true),
            'currency' => 'EUR',
            'enabled' => true,
            'server_knowledge' => null,
        ];
    }

    public function disabled(): static
    {
        return $this->state(['enabled' => false]);
    }
}
