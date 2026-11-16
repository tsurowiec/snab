<?php

use App\Models\User;
use App\Services\Rates\NbpRateService;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->service = app(NbpRateService::class);
});

it('returns the rate published on the requested date', function () {
    fakeApis(nbpRates: ['EUR' => ['2026-01-14' => 4.20, '2026-01-15' => 4.2531]]);

    expect($this->service->rateFor($this->user, 'EUR', '2026-01-15'))
        ->toBe(['rate' => 4.2531, 'rateDate' => '2026-01-15']);
});

it('falls back to the most recent rate within the previous 7 days', function () {
    // 2026-01-17 is a Saturday: Friday's table applies.
    fakeApis(nbpRates: ['EUR' => ['2026-01-15' => 4.20, '2026-01-16' => 4.30]]);

    expect($this->service->rateFor($this->user, 'eur', '2026-01-17'))
        ->toBe(['rate' => 4.30, 'rateDate' => '2026-01-16']);

    Http::assertSent(fn ($request) => str_contains($request->url(), '/exchangerates/rates/a/eur/2026-01-10/2026-01-17/'));
});

it('caches rates per user in the database', function () {
    fakeApis(nbpRates: ['EUR' => ['2026-01-15' => 4.25]]);

    $this->service->rateFor($this->user, 'EUR', '2026-01-15');
    $this->service->rateFor($this->user, 'EUR', '2026-01-15');

    Http::assertSentCount(1);
    expect($this->user->exchangeRates()->count())->toBe(1)
        ->and($this->user->exchangeRates()->first())
        ->currency->toBe('EUR')
        ->date->toBe('2026-01-15')
        ->rate->toBe(4.25)
        ->rate_date->toBe('2026-01-15');
});

it('does not share cached rates between users', function () {
    fakeApis(nbpRates: ['EUR' => ['2026-01-15' => 4.25]]);
    $other = User::factory()->create();

    $this->service->rateFor($this->user, 'EUR', '2026-01-15');
    $this->service->rateFor($other, 'EUR', '2026-01-15');

    Http::assertSentCount(2);
    expect($other->exchangeRates()->count())->toBe(1);
});

it('does not cache a fallback rate for today, since NBP may still publish one', function () {
    $this->travelTo('2026-01-16 09:00');
    fakeApis(nbpRates: ['EUR' => ['2026-01-15' => 4.25]]);

    expect($this->service->rateFor($this->user, 'EUR', '2026-01-16'))->toBe(['rate' => 4.25, 'rateDate' => '2026-01-15']);
    expect($this->user->exchangeRates()->count())->toBe(0);
});

it('returns null when NBP has no data', function () {
    fakeApis();

    expect($this->service->rateFor($this->user, 'EUR', '2026-01-15'))->toBeNull();
    expect($this->user->exchangeRates()->count())->toBe(0);
});
