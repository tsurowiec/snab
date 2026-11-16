<?php

use App\Services\Anthropic\Pricing;

it('computes micro-dollars from token counts', function () {
    expect(Pricing::costMicros('claude-sonnet-5-5', 1_000_000, 0))->toBe(2_000_000)
        ->and(Pricing::costMicros('claude-sonnet-5-5', 3000, 1000, 1000, 10_000))->toBe(6000 + 10_000 + 2500 + 1000)
        ->and(Pricing::costMicros('claude-opus-5-5', 1000, 1000))->toBe(24_000);
});

it('returns null for unknown models', function () {
    expect(Pricing::costMicros('some-other-model', 1, 1))->toBeNull();
});
