<?php

namespace App\Services\Conversions;

final readonly class ConversionResult
{
    /**
     * @param  list<array{id: string, amount: int, memo: string}>  $updates
     */
    public function __construct(
        public int $converted,
        public int $ignored,
        public bool $dryRun,
        public array $updates,
    ) {}
}
