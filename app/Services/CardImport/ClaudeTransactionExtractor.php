<?php

namespace App\Services\CardImport;

use Anthropic\Client;
use App\Models\LlmUsage;
use App\Models\User;
use App\Services\Anthropic\ScreenshotReader;
use App\Services\Anthropic\UsageRecorder;
use Carbon\CarbonImmutable;
use Carbon\Exceptions\InvalidFormatException;
use Psr\Http\Client\ClientInterface;

/**
 * Sends a Revolut screenshot to Claude and gets the transactions back as structured JSON.
 */
class ClaudeTransactionExtractor implements TransactionExtractor
{
    public const MODEL = ScreenshotReader::MODEL;

    public const MIME_TYPES = ScreenshotReader::MIME_TYPES;

    private const SYSTEM = <<<'TXT'
        You read screenshots of the Revolut app's transaction list for a credit card and return the transactions as JSON.

        Layout: the list is grouped by day. Each group starts with a date header ("Today", "Yesterday", or a date such as
        "12 November" / "Nov 12", sometimes with a year). Under each header are rows; each row shows the merchant name,
        the time (HH:MM) and the amount. Some rows also show a "Pending", "Declined" or "Reverted" label.

        The card account is always in PLN: the main amount of every row is in PLN (zł). For purchases made in another
        currency the row additionally shows the original amount in that currency.

        Rules:
        - Return every transaction row that is fully visible. Skip rows cut off at the top or bottom edge, and skip
          anything that is not a transaction (balances, buttons, section totals, ads).
        - date: resolve the header of the row's group to YYYY-MM-DD using the "today" date given by the user.
          Headers without a year are in the most recent past occurrence of that day (never in the future).
          If a row is above the first visible header, use the header that would logically precede it only if it
          is certain; otherwise skip the row.
        - time: the row's time as HH:MM (24h).
        - merchant: exactly as shown.
        - amount: the main amount as a decimal string with a dot, signed: money leaving the card (purchases, fees)
          is negative, money coming in (refunds, repayments, top-ups) is positive. Example: "-42.50".
        - currency: always "PLN" (the main amount).
        - original_amount / original_currency: the amount in the foreign currency and its ISO 4217 code
          (€ → "EUR", $ → "USD", £ → "GBP") when the row shows one, signed the same way; otherwise null.
        - status: "pending", "declined" or "reverted" when the row says so, otherwise "completed".
        - If the image is not a Revolut transaction list, return an empty list.
        TXT;

    private readonly ScreenshotReader $reader;

    /** @param  ClientInterface|null  $transporter  PSR-18 client override (tests) */
    public function __construct(UsageRecorder $usage, ?ClientInterface $transporter = null)
    {
        $this->reader = new ScreenshotReader($usage, $transporter);
    }

    public function extract(User $user, string $imageBytes, string $mimeType): array
    {
        return self::parse($this->reader->read(
            $user,
            LlmUsage::FEATURE_CARD_IMPORT,
            self::SYSTEM,
            'Today is '.CarbonImmutable::now()->format('l, Y-m-d').'. Extract the transactions.',
            self::schema(),
            $imageBytes,
            $mimeType,
        ));
    }

    public function client(string $apiKey): Client
    {
        return $this->reader->client($apiKey);
    }

    /**
     * Turn the structured response into DTOs. Rows that can't be parsed are skipped.
     *
     * @param  array<mixed>  $data
     * @return list<ExtractedTransaction>
     */
    public static function parse(array $data): array
    {
        $rows = is_array($data['transactions'] ?? null) ? $data['transactions'] : [];
        $result = [];

        foreach ($rows as $row) {
            if (! is_array($row)) {
                continue;
            }

            try {
                $occurredAt = CarbonImmutable::createFromFormat('!Y-m-d H:i', ($row['date'] ?? '').' '.($row['time'] ?? ''));
            } catch (InvalidFormatException) {
                continue;
            }
            $amount = ScreenshotReader::milliunits($row['amount'] ?? null);
            $merchant = trim((string) ($row['merchant'] ?? ''));
            $currency = strtoupper(trim((string) ($row['currency'] ?? '')));

            if (! $occurredAt instanceof CarbonImmutable || $amount === null || $merchant === '' || strlen($currency) !== 3) {
                continue;
            }

            $originalAmount = ScreenshotReader::milliunits($row['original_amount'] ?? null);
            $originalCurrency = is_string($row['original_currency'] ?? null) ? strtoupper(trim($row['original_currency'])) : null;

            // The card is billed in PLN: if the model put the PLN amount in the "original" slot, swap them back.
            if ($currency !== ExtractedTransaction::ACCOUNT_CURRENCY && $originalCurrency === ExtractedTransaction::ACCOUNT_CURRENCY && $originalAmount !== null) {
                [$amount, $originalAmount, $currency, $originalCurrency] = [$originalAmount, $amount, $originalCurrency, $currency];
            }
            $status = in_array($row['status'] ?? null, ExtractedTransaction::STATUSES, true) ? $row['status'] : 'completed';

            $result[] = new ExtractedTransaction(
                occurredAt: $occurredAt,
                description: $merchant,
                amount: $amount,
                currency: $currency,
                originalAmount: $originalAmount !== null && $originalCurrency !== null && strlen($originalCurrency) === 3 ? $originalAmount : null,
                originalCurrency: $originalAmount !== null && $originalCurrency !== null && strlen($originalCurrency) === 3 ? $originalCurrency : null,
                status: $status,
            );
        }

        return $result;
    }

    /** @return array<string, mixed> */
    private static function schema(): array
    {
        $nullableString = ['type' => ['string', 'null']];

        return [
            'type' => 'object',
            'properties' => [
                'transactions' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'properties' => [
                            'date' => ['type' => 'string', 'description' => 'YYYY-MM-DD'],
                            'time' => ['type' => 'string', 'description' => 'HH:MM, 24h'],
                            'merchant' => ['type' => 'string'],
                            'amount' => ['type' => 'string', 'description' => 'Signed decimal, e.g. -42.50'],
                            'currency' => ['type' => 'string', 'description' => 'ISO 4217 code'],
                            'original_amount' => $nullableString,
                            'original_currency' => $nullableString,
                            'status' => ['type' => 'string', 'enum' => ExtractedTransaction::STATUSES],
                        ],
                        'required' => ['date', 'time', 'merchant', 'amount', 'currency', 'original_amount', 'original_currency', 'status'],
                        'additionalProperties' => false,
                    ],
                ],
            ],
            'required' => ['transactions'],
            'additionalProperties' => false,
        ];
    }
}
