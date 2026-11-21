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

        Layout: the list is grouped by day. Each group starts with a date header such as "Wtorek, 03.11.2026"
        (weekday, then DD.MM.YYYY: day first, so 01.11.2026 is 1 November), or "Dzisiaj" / "Wczoraj". Under each
        header are rows. Each row has:
        - an icon on the left;
        - the merchant or counterparty in bold (e.g. "ROSSMANN 01, Zory"). Long names wrap onto a second bold line,
          e.g. "PIEKARNIA KRISTOF" / "S-43, ZORY" is one name;
        - under it, in grey, the transaction type ("transakcja kartą debetową", "przelew natychmiastowy", "BLIK");
        - on the right, the amount: "- 22,98 PLN" or "+ 2 000,00 PLN" (comma decimals, spaces between thousands,
          a space after the sign; incoming amounts are green with "+").

        Rules:
        - Return every transaction row that is fully visible, including rows greyed out or faded near the bottom
          edge. Skip rows cut off at the top or bottom edge, and skip anything that is not a transaction (balances,
          buttons, filters, section totals, ads, search fields).
        - date: resolve the row's group header to YYYY-MM-DD. Headers without a year ("Dzisiaj", "Wczoraj",
          "12 listopada") are resolved with the "today" date given by the user, in the most recent past occurrence
          of that day (never in the future). If a row is above the first visible header, skip it.
        - merchant: the bold name exactly as shown, wrapped lines joined with a single space, keeping the city
          suffix and punctuation (e.g. "'ANANAS', Zory"). Never use the grey transaction type. If the bold line is
          itself only a generic label ("Transakcja kartą", "Płatność kartą", "Przelew", "BLIK") and a real merchant
          is shown elsewhere in the row, return the real merchant; otherwise return the label as shown.
        - amount: the PLN amount as a decimal string with a dot and no thousands separators, signed: money leaving
          the account is negative, money coming in is positive. "- 22,98 PLN" → "-22.98", "+ 2 000,00 PLN" →
          "2000.00". If a foreign-currency amount is also shown, use the PLN one.
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
