<?php

namespace App\Services\Conversions;

use Illuminate\Contracts\Support\Arrayable;

/**
 * @implements Arrayable<string, mixed>
 */
final readonly class PendingTransaction implements Arrayable
{
    public function __construct(
        public string $id,
        public string $date,
        public int $amount,
        public ?string $payeeName,
        public ?string $memo,
        public float $rate,
        public string $rateDate,
        public int $convertedAmount,
    ) {}

    /**
     * @return array{id: string, date: string, amount: int, payeeName: string|null, memo: string|null, rate: float, rateDate: string, convertedAmount: int}
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'date' => $this->date,
            'amount' => $this->amount,
            'payeeName' => $this->payeeName,
            'memo' => $this->memo,
            'rate' => $this->rate,
            'rateDate' => $this->rateDate,
            'convertedAmount' => $this->convertedAmount,
        ];
    }
}
