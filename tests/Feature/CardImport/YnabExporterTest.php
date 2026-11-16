<?php

use App\Models\CardTransaction;
use App\Models\Setting;
use App\Models\User;
use App\Services\CardImport\YnabExporter;
use App\Services\Ynab\YnabException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    $this->user = User::factory()->withYnabToken()->create();
    $this->user->setSetting(Setting::YNAB_PLAN_ID, 'plan-1');
    $this->user->setSetting(Setting::CARD_IMPORT_YNAB_ACCOUNT, 'acc-revolut');
});

function cardTx(User $user, array $attributes = []): CardTransaction
{
    return CardTransaction::factory()->for($user)->create($attributes);
}

it('creates unapproved, uncleared YNAB transactions with an import id', function () {
    fakeApis();
    $tx = cardTx($this->user, ['occurred_at' => '2026-11-15 14:32', 'description' => 'Booking.com', 'amount' => -1234560, 'original_amount' => -285000, 'original_currency' => 'EUR']);

    expect(app(YnabExporter::class)->export($this->user))->toBe(['sent' => 1, 'alreadyInYnab' => 0]);

    Http::assertSent(fn (Request $r) => $r->method() === 'POST'
        && str_ends_with($r->url(), '/plans/plan-1/transactions')
        && $r['transactions'] === [[
            'account_id' => 'acc-revolut',
            'date' => '2026-11-15',
            'amount' => -1234560,
            'payee_name' => 'Booking.com',
            'memo' => null,
            'cleared' => 'uncleared',
            'approved' => false,
            'import_id' => $tx->importId(),
        ]]);

    expect($tx->fresh()->status)->toBe(CardTransaction::STATUS_RECORDED)
        ->and($tx->fresh()->ynab_transaction_id)->toBe('ynab-0')
        ->and(strlen($tx->importId()))->toBeLessThanOrEqual(36);
});

it('only sends transactions that are not in YNAB yet', function () {
    fakeApis();
    cardTx($this->user, ['status' => CardTransaction::STATUS_RECORDED]);
    cardTx($this->user);

    $exporter = app(YnabExporter::class);

    expect($exporter->export($this->user)['sent'])->toBe(1)
        ->and($exporter->export($this->user))->toBe(['sent' => 0, 'alreadyInYnab' => 0]);

    Http::assertSentCount(1);
});

it('treats YNAB duplicate import ids as already recorded', function () {
    $tx = cardTx($this->user);
    fakeApis(duplicateImportIds: [$tx->importId()]);

    expect(app(YnabExporter::class)->export($this->user))->toBe(['sent' => 0, 'alreadyInYnab' => 1])
        ->and($tx->fresh()->status)->toBe(CardTransaction::STATUS_RECORDED);
});

it('needs a target account', function () {
    $this->user->settings()->where('key', Setting::CARD_IMPORT_YNAB_ACCOUNT)->delete();
    cardTx($this->user);

    expect(fn () => app(YnabExporter::class)->export($this->user))->toThrow(YnabException::class, 'Settings → YNAB');
});

it('keeps transactions unsent when YNAB fails', function () {
    Http::fake(['*' => Http::response(['error' => ['id' => '500', 'name' => 'internal', 'detail' => 'boom']], 500)]);
    $tx = cardTx($this->user);

    expect(fn () => app(YnabExporter::class)->export($this->user))->toThrow(YnabException::class, 'boom')
        ->and($tx->fresh()->status)->toBe(CardTransaction::STATUS_NEW);
});
