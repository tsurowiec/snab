<?php

namespace App\Services\CardImport;

use Anthropic\Client;
use Anthropic\Core\Exceptions\AnthropicException;
use Anthropic\Core\Exceptions\AuthenticationException;
use Anthropic\Messages\TextBlock;
use App\Models\User;
use Carbon\CarbonImmutable;
use Carbon\Exceptions\InvalidFormatException;
use Psr\Http\Client\ClientInterface;

/**
 * Sends a Revolut screenshot to Claude and gets the transactions back as structured JSON.
 */
class ClaudeTransactionExtractor implements TransactionExtractor
{
    public const MODEL = 'claude-opus-5-5';

    public const MIME_TYPES = ['image/png', 'image/jpeg', 'image/webp', 'image/gif'];

    private const SYSTEM = <<<'TXT'
        You read screenshots of the Revolut app's transaction list for a credit card and return the transactions as JSON.

        Layout: the list is grouped by day. Each group starts with a date header ("Today", "Yesterday", or a date such as
        "12 November" / "Nov 12", sometimes with a year). Under each header are rows; each row shows the merchant name,
        the time (HH:MM) and the amount. Some rows also show an amount in another currency, a "Pending", "Declined" or
        "Reverted" label.

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
        - currency: ISO 4217 code of the main amount (zł/PLN → "PLN", € → "EUR", $ → "USD", £ → "GBP").
        - original_amount / original_currency: the secondary amount and its currency when the row shows one
          (signed the same way), otherwise null.
        - status: "pending", "declined" or "reverted" when the row says so, otherwise "completed".
        - If the image is not a Revolut transaction list, return an empty list.
        TXT;

    /** @param  ClientInterface|null  $transporter  PSR-18 client override (tests) */
    public function __construct(private readonly ?ClientInterface $transporter = null) {}

    public function extract(User $user, string $imageBytes, string $mimeType): array
    {
        $apiKey = $user->anthropicApiKey();
        if ($apiKey === null) {
            throw new ExtractionException(__('No Anthropic API key — add one in Settings → AI.'));
        }

        if (! in_array($mimeType, self::MIME_TYPES, true)) {
            throw new ExtractionException(__('Unsupported image type :type.', ['type' => $mimeType]));
        }

        try {
            $message = (new Client(apiKey: $apiKey, requestOptions: ['transporter' => $this->transporter, 'maxRetries' => $this->transporter ? 0 : null]))->messages->create(
                model: self::MODEL,
                maxTokens: 16000,
                system: self::SYSTEM,
                outputConfig: [
                    'effort' => 'medium',
                    'format' => ['type' => 'json_schema', 'schema' => self::schema()],
                ],
                messages: [[
                    'role' => 'user',
                    'content' => [
                        ['type' => 'image', 'source' => ['type' => 'base64', 'mediaType' => $mimeType, 'data' => base64_encode($imageBytes)]],
                        ['type' => 'text', 'text' => 'Today is '.CarbonImmutable::now()->format('l, Y-m-d').'. Extract the transactions.'],
                    ],
                ]],
            );
        } catch (AuthenticationException) {
            throw new ExtractionException(__('Anthropic rejected the API key — check Settings → AI.'));
        } catch (AnthropicException $e) {
            throw new ExtractionException(__('Claude request failed: :message', ['message' => $e->getMessage()]), previous: $e);
        }

        if ($message->stopReason === 'refusal') {
            throw new ExtractionException(__('Claude declined to read this image.'));
        }
        if ($message->stopReason === 'max_tokens') {
            throw new ExtractionException(__('The response was cut off — try a smaller screenshot.'));
        }

        foreach ($message->content as $block) {
            if ($block instanceof TextBlock) {
                $data = json_decode($block->text, true);
                if (! is_array($data)) {
                    throw new ExtractionException(__('Claude returned invalid JSON.'));
                }

                return self::parse($data);
            }
        }

        throw new ExtractionException(__('Claude returned no transactions.'));
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
            $amount = self::milliunits($row['amount'] ?? null);
            $merchant = trim((string) ($row['merchant'] ?? ''));
            $currency = strtoupper(trim((string) ($row['currency'] ?? '')));

            if (! $occurredAt instanceof CarbonImmutable || $amount === null || $merchant === '' || strlen($currency) !== 3) {
                continue;
            }

            $originalAmount = self::milliunits($row['original_amount'] ?? null);
            $originalCurrency = is_string($row['original_currency'] ?? null) ? strtoupper(trim($row['original_currency'])) : null;
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

    /** "-1 234,50" / "-1234.50" / -12.5 → milliunits, or null if not a number. */
    private static function milliunits(mixed $value): ?int
    {
        if (is_int($value) || is_float($value)) {
            return (int) round($value * 1000);
        }
        if (! is_string($value)) {
            return null;
        }

        $clean = str_replace([' ', "\u{00A0}", "\u{2212}"], ['', '', '-'], trim($value));
        $clean = str_replace(',', '.', $clean);

        return is_numeric($clean) ? (int) round((float) $clean * 1000) : null;
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
