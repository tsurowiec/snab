<?php

namespace App\Services\AliorPayees;

use Carbon\CarbonImmutable;

/** One Alior transaction read from a screenshot. Amount in milliunits, spending negative (PLN account). */
final readonly class AliorRow
{
    public function __construct(
        public CarbonImmutable $date,
        public string $merchant,
        public int $amount,
    ) {}

    /** Lowercased, trimmed, whitespace collapsed: used to compare payees and to build the import_id. */
    public static function normalize(?string $name): string
    {
        return mb_strtolower(trim((string) preg_replace('/\s+/u', ' ', (string) $name)));
    }

    /** YNAB import_id (max 36 chars) for rows this tool creates, so YNAB refuses to create one twice. */
    public function importId(): string
    {
        return 'SNAB:A:'.substr(hash('sha256', implode('|', [$this->date->toDateString(), $this->amount, self::normalize($this->merchant)])), 0, 29);
    }

    /** @return array{date: string, merchant: string, amount: int} */
    public function toArray(): array
    {
        return ['date' => $this->date->toDateString(), 'merchant' => $this->merchant, 'amount' => $this->amount];
    }

    /** @param  array{date: string, merchant: string, amount: int}  $row */
    public static function fromArray(array $row): self
    {
        return new self(
            CarbonImmutable::createFromFormat('!Y-m-d', $row['date']) ?: throw new \InvalidArgumentException('Bad date'),
            $row['merchant'],
            $row['amount'],
        );
    }
}
