<?php

namespace App\Services\CardImport;

use App\Models\CardTransaction;
use Carbon\CarbonImmutable;

/** One transaction row read from a screenshot. Amounts are milliunits (negative = spending). */
final readonly class ExtractedTransaction
{
    public const STATUSES = ['completed', 'pending', 'declined', 'reverted'];

    public function __construct(
        public CarbonImmutable $occurredAt,
        public string $description,
        public int $amount,
        public string $currency,
        public ?int $originalAmount = null,
        public ?string $originalCurrency = null,
        public string $status = 'completed',
    ) {}

    public function hash(): string
    {
        return CardTransaction::hashFor($this->occurredAt, $this->amount, $this->currency, $this->description);
    }

    /** Declined/reverted rows never moved money, so they aren't selected by default. */
    public function selectedByDefault(): bool
    {
        return in_array($this->status, ['completed', 'pending'], true);
    }

    /**
     * @return array{occurredAt: string, description: string, amount: int, currency: string, originalAmount: int|null, originalCurrency: string|null, status: string, hash: string}
     */
    public function toArray(): array
    {
        return [
            'occurredAt' => $this->occurredAt->format('Y-m-d H:i'),
            'description' => $this->description,
            'amount' => $this->amount,
            'currency' => $this->currency,
            'originalAmount' => $this->originalAmount,
            'originalCurrency' => $this->originalCurrency,
            'status' => $this->status,
            'hash' => $this->hash(),
        ];
    }

    /**
     * @param  array{occurredAt: string, description: string, amount: int, currency: string, originalAmount?: int|null, originalCurrency?: string|null, status?: string}  $row
     */
    public static function fromArray(array $row): self
    {
        return new self(
            occurredAt: CarbonImmutable::createFromFormat('Y-m-d H:i', $row['occurredAt']) ?: throw new \InvalidArgumentException('Bad date'),
            description: $row['description'],
            amount: $row['amount'],
            currency: $row['currency'],
            originalAmount: $row['originalAmount'] ?? null,
            originalCurrency: $row['originalCurrency'] ?? null,
            status: $row['status'] ?? 'completed',
        );
    }
}
