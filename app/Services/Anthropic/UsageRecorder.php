<?php

namespace App\Services\Anthropic;

use Anthropic\Beta\Messages\BetaFallbackMessageIterationUsage;
use Anthropic\Beta\Messages\BetaMessage;
use Anthropic\Beta\Messages\BetaMessageIterationUsage;
use App\Models\LlmUsage;
use App\Models\User;

/** Stores the token usage + estimated cost of every Claude response. */
class UsageRecorder
{
    public function record(User $user, string $feature, BetaMessage $message): LlmUsage
    {
        $usage = $message->usage;

        return $user->llmUsages()->create([
            'feature' => $feature,
            'model' => $message->model,
            'input_tokens' => $usage->inputTokens,
            'output_tokens' => $usage->outputTokens,
            'cache_creation_input_tokens' => $usage->cacheCreationInputTokens ?? 0,
            'cache_read_input_tokens' => $usage->cacheReadInputTokens ?? 0,
            'cost_micros' => $this->cost($message),
            'stop_reason' => $message->stopReason,
        ]);
    }

    /** With a server-side fallback the turn is billed per model that ran, so price each iteration separately. */
    private function cost(BetaMessage $message): ?int
    {
        $usage = $message->usage;
        $iterations = array_filter(
            $usage->iterations ?? [],
            fn (mixed $i): bool => $i instanceof BetaMessageIterationUsage || $i instanceof BetaFallbackMessageIterationUsage,
        );

        if ($iterations === []) {
            return Pricing::costMicros(
                $message->model,
                $usage->inputTokens,
                $usage->outputTokens,
                $usage->cacheCreationInputTokens ?? 0,
                $usage->cacheReadInputTokens ?? 0,
            );
        }

        $total = 0;
        foreach ($iterations as $i) {
            $cost = Pricing::costMicros($i->model ?? $message->model, $i->inputTokens, $i->outputTokens, $i->cacheCreationInputTokens, $i->cacheReadInputTokens);
            if ($cost === null) {
                return null;
            }
            $total += $cost;
        }

        return $total;
    }
}
