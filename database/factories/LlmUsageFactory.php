<?php

namespace Database\Factories;

use App\Models\LlmUsage;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<LlmUsage>
 */
class LlmUsageFactory extends Factory
{
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'feature' => LlmUsage::FEATURE_CARD_IMPORT,
            'model' => 'claude-sonnet-5-5',
            'input_tokens' => 3000,
            'output_tokens' => 1000,
            'cost_micros' => 16000,
            'stop_reason' => 'end_turn',
        ];
    }
}
