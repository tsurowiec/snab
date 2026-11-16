<?php

namespace Database\Factories;

use App\Models\CardTransaction;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Carbon;

/**
 * @extends Factory<CardTransaction>
 */
class CardTransactionFactory extends Factory
{
    public function definition(): array
    {
        $occurredAt = Carbon::parse(fake()->dateTimeBetween('-30 days'))->startOfMinute();
        $amount = -fake()->numberBetween(1, 500) * 1000;
        $description = fake()->company();

        return [
            'user_id' => User::factory(),
            'source' => 'revolut',
            'occurred_at' => $occurredAt,
            'description' => $description,
            'amount' => $amount,
            'currency' => 'PLN',
            'hash' => CardTransaction::hashFor($occurredAt, $amount, 'PLN', $description),
            'status' => CardTransaction::STATUS_NEW,
        ];
    }
}
