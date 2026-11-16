<?php

namespace App\Services\Anthropic;

/**
 * Anthropic list prices in USD per million tokens (first-party API, Nov 2026).
 * Tokens × $/MTok = micro-dollars, so costs are stored as integers.
 * Update when prices change; unknown models get a null cost.
 */
final class Pricing
{
    /** @var array<string, array{input: float, output: float, cacheWrite: float, cacheRead: float}> */
    public const MODELS = [
        'claude-sonnet-5-5' => ['input' => 2.00, 'output' => 10.00, 'cacheWrite' => 2.50, 'cacheRead' => 0.10],
        'claude-sonnet-5' => ['input' => 2.00, 'output' => 10.00, 'cacheWrite' => 2.50, 'cacheRead' => 0.20],
        'claude-opus-5-5' => ['input' => 4.00, 'output' => 20.00, 'cacheWrite' => 5.00, 'cacheRead' => 0.20],
        'claude-opus-4-8' => ['input' => 5.00, 'output' => 25.00, 'cacheWrite' => 6.25, 'cacheRead' => 0.50],
        'claude-haiku-5-5' => ['input' => 0.10, 'output' => 0.50, 'cacheWrite' => 0.125, 'cacheRead' => 0.01],
    ];

    public static function costMicros(string $model, int $input, int $output, int $cacheWrite = 0, int $cacheRead = 0): ?int
    {
        $price = self::MODELS[$model] ?? null;
        if ($price === null) {
            foreach (self::MODELS as $id => $candidate) {
                if (str_starts_with($model, $id.'-')) { // e.g. a dated / suffixed id
                    $price = $candidate;
                    break;
                }
            }
        }

        if ($price === null) {
            return null;
        }

        return (int) round(
            $input * $price['input']
            + $output * $price['output']
            + $cacheWrite * $price['cacheWrite']
            + $cacheRead * $price['cacheRead']
        );
    }
}
