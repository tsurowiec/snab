<?php

namespace App\Services\Anthropic;

use Anthropic\Beta\Messages\BetaTextBlock;
use Anthropic\Client;
use Anthropic\Core\Exceptions\AnthropicException;
use Anthropic\Core\Exceptions\AuthenticationException;
use App\Models\User;
use App\Services\CardImport\ExtractionException;
use Psr\Http\Client\ClientInterface;

/**
 * Sends one screenshot to Claude with a strict JSON schema and returns the decoded reply.
 * Shared by the screenshot tools; each one brings its own system prompt, schema and parser.
 */
class ScreenshotReader
{
    public const MODEL = 'claude-sonnet-5-5';

    public const MIME_TYPES = ['image/png', 'image/jpeg', 'image/webp', 'image/gif'];

    /** @param  ClientInterface|null  $transporter  PSR-18 client override (tests) */
    public function __construct(
        private readonly UsageRecorder $usage,
        private readonly ?ClientInterface $transporter = null,
    ) {}

    /**
     * @param  array<string, mixed>  $schema
     * @return array<mixed>
     *
     * @throws ExtractionException
     */
    public function read(User $user, string $feature, string $system, string $prompt, array $schema, string $imageBytes, string $mimeType): array
    {
        $apiKey = $user->anthropicApiKey();
        if ($apiKey === null) {
            throw new ExtractionException(__('No Anthropic API key — add one in Settings → AI.'));
        }

        if (! in_array($mimeType, self::MIME_TYPES, true)) {
            throw new ExtractionException(__('Unsupported image type :type.', ['type' => $mimeType]));
        }

        try {
            // Beta endpoint for server-side fallback: if Sonnet declines on policy grounds,
            // Anthropic retries on its default fallback model within the same call.
            $message = $this->client($apiKey)->beta->messages->create(
                model: self::MODEL,
                betas: ['server-side-fallback-2026-07-01'],
                fallbacks: 'default',
                maxTokens: 16000,
                system: $system,
                outputConfig: [
                    'effort' => 'medium',
                    'format' => ['type' => 'json_schema', 'schema' => $schema],
                ],
                messages: [[
                    'role' => 'user',
                    'content' => [
                        ['type' => 'image', 'source' => ['type' => 'base64', 'mediaType' => $mimeType, 'data' => base64_encode($imageBytes)]],
                        ['type' => 'text', 'text' => $prompt],
                    ],
                ]],
            );
        } catch (AuthenticationException) {
            throw new ExtractionException(__('Anthropic rejected the API key — check Settings → AI.'));
        } catch (AnthropicException $e) {
            throw new ExtractionException(__('Claude request failed: :message', ['message' => $e->getMessage()]), previous: $e);
        }

        // Billed even when the reply is unusable, so record before checking it.
        $this->usage->record($user, $feature, $message);

        if ($message->stopReason === 'refusal') {
            throw new ExtractionException(__('Claude declined to read this image.'));
        }
        if ($message->stopReason === 'max_tokens') {
            throw new ExtractionException(__('The response was cut off — try a smaller screenshot.'));
        }

        foreach ($message->content as $block) {
            if ($block instanceof BetaTextBlock) {
                $data = json_decode($block->text, true);
                if (! is_array($data)) {
                    throw new ExtractionException(__('Claude returned invalid JSON.'));
                }

                return $data;
            }
        }

        throw new ExtractionException(__('Claude returned no transactions.'));
    }

    /** Only pass options that are set: a null `transporter` would override the SDK's auto-discovered HTTP client. */
    public function client(string $apiKey): Client
    {
        $options = $this->transporter === null ? null : ['transporter' => $this->transporter, 'maxRetries' => 0];

        return new Client(apiKey: $apiKey, requestOptions: $options);
    }

    /** "-1 234,50" / "-1234.50" / -12.5 → milliunits, or null if not a number. */
    public static function milliunits(mixed $value): ?int
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
}
