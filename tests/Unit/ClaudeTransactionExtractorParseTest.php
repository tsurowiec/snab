<?php

use App\Services\CardImport\ClaudeTransactionExtractor;

it('parses rows into milliunit DTOs', function () {
    $rows = ClaudeTransactionExtractor::parse(['transactions' => [
        ['date' => '2026-11-15', 'time' => '14:32', 'merchant' => 'Lidl', 'amount' => '-42.50', 'currency' => 'pln', 'original_amount' => null, 'original_currency' => null, 'status' => 'completed'],
        ['date' => '2026-11-14', 'time' => '09:05', 'merchant' => 'Booking.com', 'amount' => '-1 234,56', 'currency' => 'PLN', 'original_amount' => '-285.00', 'original_currency' => 'EUR', 'status' => 'pending'],
        ['date' => '2026-11-14', 'time' => '08:00', 'merchant' => 'Refund', 'amount' => '10', 'currency' => 'PLN', 'original_amount' => null, 'original_currency' => null, 'status' => 'weird'],
    ]]);

    expect($rows)->toHaveCount(3)
        ->and($rows[0]->amount)->toBe(-42500)
        ->and($rows[0]->currency)->toBe('PLN')
        ->and($rows[0]->occurredAt->format('Y-m-d H:i'))->toBe('2026-11-15 14:32')
        ->and($rows[1]->amount)->toBe(-1234560)
        ->and($rows[1]->originalAmount)->toBe(-285000)
        ->and($rows[1]->originalCurrency)->toBe('EUR')
        ->and($rows[1]->status)->toBe('pending')
        ->and($rows[2]->amount)->toBe(10000)
        ->and($rows[2]->status)->toBe('completed');
});

it('skips rows it cannot parse', function () {
    $rows = ClaudeTransactionExtractor::parse(['transactions' => [
        ['date' => 'yesterday', 'time' => '14:32', 'merchant' => 'Lidl', 'amount' => '-1', 'currency' => 'PLN'],
        ['date' => '2026-11-15', 'time' => '14:32', 'merchant' => '', 'amount' => '-1', 'currency' => 'PLN'],
        ['date' => '2026-11-15', 'time' => '14:32', 'merchant' => 'Lidl', 'amount' => 'abc', 'currency' => 'PLN'],
        'garbage',
    ]]);

    expect($rows)->toBe([])
        ->and(ClaudeTransactionExtractor::parse([]))->toBe([]);
});

it('drops the original amount when its currency is missing', function () {
    $rows = ClaudeTransactionExtractor::parse(['transactions' => [
        ['date' => '2026-11-15', 'time' => '14:32', 'merchant' => 'X', 'amount' => '-1', 'currency' => 'PLN', 'original_amount' => '-2', 'original_currency' => null, 'status' => 'completed'],
    ]]);

    expect($rows[0]->originalAmount)->toBeNull();
});
