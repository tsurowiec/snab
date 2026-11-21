<?php

namespace App\Services\AliorPayees;

use App\Models\LlmUsage;
use App\Models\User;
use App\Services\Anthropic\ScreenshotReader;
use Carbon\CarbonImmutable;
use Carbon\Exceptions\InvalidFormatException;

/** Sends an Alior app screenshot to Claude and gets date, merchant and amount of each transaction back. */
class ClaudePayeeExtractor implements PayeeExtractor
{
    private const SYSTEM = <<<'TXT'
        You read screenshots of the Alior Bank app's (Poland) transaction history and return the transactions as JSON.

        Layout: the list is grouped by day. Each group starts with a date header ("Dzisiaj", "Wczoraj", or a date such
        as "12 listopada", "12.11.2026", sometimes with a weekday or year). Under each header are rows; each row shows
        the merchant or counterparty, sometimes a second line with a description or category, and the amount in PLN (zł).

        Rules:
        - Return every transaction row that is fully visible. Skip rows cut off at the top or bottom edge, and skip
          anything that is not a transaction (balances, buttons, section totals, ads, search fields).
        - date: resolve the row's group header to YYYY-MM-DD using the "today" date given by the user. Headers
          without a year are in the most recent past occurrence of that day (never in the future). If a row is above
          the first visible header, skip it.
        - merchant: the merchant or counterparty name exactly as shown. Generic labels such as "Transakcja kartą",
          "Płatność kartą", "Przelew" or "BLIK" are not merchants: if the row also shows the real merchant
          (e.g. on the second line), return that; otherwise return the label as shown.
        - amount: the PLN amount as a decimal string with a dot, signed: money leaving the account is negative,
          money coming in is positive. Example: "-42.50". If a foreign-currency amount is also shown, use the PLN one.
        - If the image is not an Alior transaction list, return an empty list.
        TXT;

    public function __construct(private readonly ScreenshotReader $reader) {}

    public function extract(User $user, string $imageBytes, string $mimeType): array
    {
        return self::parse($this->reader->read(
            $user,
            LlmUsage::FEATURE_ALIOR_PAYEES,
            self::SYSTEM,
            'Today is '.CarbonImmutable::now()->format('l, Y-m-d').'. Extract the transactions.',
            self::schema(),
            $imageBytes,
            $mimeType,
        ));
    }

    /**
     * Turn the structured response into DTOs. Rows that can't be parsed are skipped.
     *
     * @param  array<mixed>  $data
     * @return list<AliorRow>
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
                $date = CarbonImmutable::createFromFormat('!Y-m-d', (string) ($row['date'] ?? ''));
            } catch (InvalidFormatException) {
                continue;
            }
            $amount = ScreenshotReader::milliunits($row['amount'] ?? null);
            $merchant = trim((string) preg_replace('/\s+/u', ' ', (string) ($row['merchant'] ?? '')));

            if (! $date instanceof CarbonImmutable || $amount === null || $amount === 0 || $merchant === '') {
                continue;
            }

            $result[] = new AliorRow($date, $merchant, $amount);
        }

        return $result;
    }

    /** @return array<string, mixed> */
    private static function schema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'transactions' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'properties' => [
                            'date' => ['type' => 'string', 'description' => 'YYYY-MM-DD'],
                            'merchant' => ['type' => 'string'],
                            'amount' => ['type' => 'string', 'description' => 'Signed PLN decimal, e.g. -42.50'],
                        ],
                        'required' => ['date', 'merchant', 'amount'],
                        'additionalProperties' => false,
                    ],
                ],
            ],
            'required' => ['transactions'],
            'additionalProperties' => false,
        ];
    }
}
