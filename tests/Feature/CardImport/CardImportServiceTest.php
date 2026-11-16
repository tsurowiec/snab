<?php

use App\Models\CardTransaction;
use App\Models\User;
use App\Services\CardImport\CardImportService;
use App\Services\CardImport\ExtractedTransaction;
use Carbon\CarbonImmutable;

function extracted(string $at = '2026-11-15 14:32', string $description = 'Lidl', int $amount = -42500, string $currency = 'PLN'): ExtractedTransaction
{
    return new ExtractedTransaction(CarbonImmutable::parse($at), $description, $amount, $currency);
}

beforeEach(fn () => $this->user = User::factory()->create());

it('flags rows that are already stored or repeated in the batch', function () {
    app(CardImportService::class)->store($this->user, [extracted()]);

    $preview = app(CardImportService::class)->preview($this->user, [
        extracted(description: 'LIDL '),  // same as stored
        extracted(at: '2026-11-15 18:00'), // new
        extracted(at: '2026-11-15 18:00'), // repeat within the batch
    ]);

    expect(array_column($preview, 'duplicate'))->toBe([true, false, true]);
});

it('stores new rows once and reports skipped ones', function () {
    $service = app(CardImportService::class);

    expect($service->store($this->user, [extracted(), extracted(at: '2026-11-14 10:00')]))->toBe(['saved' => 2, 'skipped' => 0])
        ->and($service->store($this->user, [extracted(), extracted(at: '2026-11-13 10:00')]))->toBe(['saved' => 1, 'skipped' => 1])
        ->and($this->user->cardTransactions()->count())->toBe(3);

    $tx = $this->user->cardTransactions()->oldest('occurred_at')->first();
    expect($tx->status)->toBe(CardTransaction::STATUS_NEW)
        ->and($tx->occurred_at->format('Y-m-d H:i'))->toBe('2026-11-13 10:00');
});

it('lets two users store the same transaction', function () {
    $other = User::factory()->create();
    $service = app(CardImportService::class);

    $service->store($this->user, [extracted()]);

    expect($service->store($other, [extracted()]))->toBe(['saved' => 1, 'skipped' => 0])
        ->and($service->preview($other, [extracted()])[0]['duplicate'])->toBeTrue();
});
