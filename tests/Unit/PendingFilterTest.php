<?php

use App\Services\Conversions\PendingFilter;

it('treats a fresh transaction as pending', function () {
    expect(PendingFilter::isPending(ynabTx()))->toBeTrue();
});

it('skips deleted transactions', function () {
    expect(PendingFilter::isPending(ynabTx(['deleted' => true])))->toBeFalse();
});

it('skips transactions already stamped with the TX marker', function (string $memo) {
    expect(PendingFilter::isPending(ynabTx(['memo' => $memo])))->toBeFalse();
})->with([
    'converted' => 'Lunch TX 42.50EUR @ 4.2531 (2024-01-15)',
    'ignored' => 'TX ignored',
]);

it('skips the unapproved half of a matched pair', function () {
    expect(PendingFilter::isPending(ynabTx(['matched_transaction_id' => 'abc', 'approved' => false])))->toBeFalse();
});

it('keeps the approved half of a matched pair', function () {
    expect(PendingFilter::isPending(ynabTx(['matched_transaction_id' => 'abc', 'approved' => true])))->toBeTrue();
});
