<?php

namespace App\Services\Ynab;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * Thin wrapper over the YNAB REST API (https://api.ynab.com/v1).
 * Amounts are in milliunits (integer, divide by 1000 for display).
 */
class YnabClient
{
    public function __construct(
        private readonly ?string $token,
        private readonly string $baseUrl = 'https://api.ynab.com/v1',
    ) {}

    public function isConfigured(): bool
    {
        return filled($this->token);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function plans(): array
    {
        return $this->get('/plans')['plans'];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function accounts(string $planId): array
    {
        return $this->get("/plans/{$planId}/accounts")['accounts'];
    }

    /**
     * @return array<string, mixed>
     */
    public function account(string $planId, string $accountId): array
    {
        return $this->get("/plans/{$planId}/accounts/{$accountId}")['account'];
    }

    /**
     * Delta fetch: with $sinceKnowledge only transactions changed since that
     * server_knowledge value are returned.
     *
     * @return array{transactions: list<array<string, mixed>>, server_knowledge: int}
     */
    public function transactionsByAccount(string $planId, string $accountId, ?int $sinceKnowledge = null): array
    {
        $query = $sinceKnowledge !== null ? ['last_knowledge_of_server' => $sinceKnowledge] : [];
        $data = $this->get("/plans/{$planId}/accounts/{$accountId}/transactions", $query);

        return [
            'transactions' => $data['transactions'],
            'server_knowledge' => (int) $data['server_knowledge'],
        ];
    }

    /**
     * @param  list<array{id: string, amount: int, memo: string}>  $transactions
     * @return array<string, mixed>
     */
    public function updateTransactions(string $planId, array $transactions): array
    {
        return $this->send('patch', "/plans/{$planId}/transactions", ['transactions' => $transactions]);
    }

    /**
     * @param  array<string, mixed>  $query
     * @return array<string, mixed>
     */
    private function get(string $path, array $query = []): array
    {
        return $this->send('get', $path, $query);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function send(string $method, string $path, array $payload = []): array
    {
        if (! $this->isConfigured()) {
            throw new YnabException('YNAB_TOKEN is not set in .env');
        }

        try {
            /** @var Response $response */
            $response = $this->request()->{$method}($path, $payload);
        } catch (ConnectionException $e) {
            throw new YnabException('Could not reach YNAB: '.$e->getMessage(), previous: $e);
        }

        if ($response->failed()) {
            $detail = $response->json('error.detail') ?? $response->json('error.name') ?? $response->reason();
            throw new YnabException("YNAB API error ({$response->status()}): {$detail}");
        }

        return $response->json('data') ?? [];
    }

    private function request(): PendingRequest
    {
        return Http::baseUrl($this->baseUrl)
            ->withToken((string) $this->token)
            ->acceptJson()
            ->timeout(20);
    }
}
