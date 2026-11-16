<?php

namespace App\Services\Anthropic;

use Anthropic\Client;
use Anthropic\Core\Exceptions\AnthropicException;
use Anthropic\Core\Exceptions\AuthenticationException;

/** Checks an Anthropic API key with a cheap call (list models) before it is stored. */
class AnthropicKeyVerifier
{
    /** @return string|null null when the key works, otherwise an error message */
    public function verify(string $apiKey): ?string
    {
        try {
            (new Client(apiKey: $apiKey))->models->list(limit: 1);
        } catch (AuthenticationException) {
            return __('Anthropic rejected this API key.');
        } catch (AnthropicException $e) {
            return __('Could not reach Anthropic: :message', ['message' => $e->getMessage()]);
        }

        return null;
    }
}
