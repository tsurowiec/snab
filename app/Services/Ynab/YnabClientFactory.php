<?php

namespace App\Services\Ynab;

use App\Models\User;

/**
 * Builds YnabClient instances. Every user brings their own personal access token
 * (stored encrypted in their settings), so there is no app-wide client.
 */
class YnabClientFactory
{
    public function __construct(private readonly string $baseUrl = 'https://api.ynab.com/v1') {}

    public function forUser(User $user): YnabClient
    {
        return $this->withToken($user->ynabToken());
    }

    public function withToken(?string $token): YnabClient
    {
        return new YnabClient($token, $this->baseUrl);
    }
}
