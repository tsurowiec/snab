<?php

namespace App\Services\Rates;

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * NBP (National Bank of Poland) table A mid-rates, cached per user in exchange_rates.
 */
class NbpRateService
{
    /** NBP doesn't publish on weekends/holidays: look back this many days. */
    public const LOOKBACK_DAYS = 7;

    public function __construct(private readonly string $baseUrl = 'https://api.nbp.pl/api') {}

    /**
     * Mid-rate for $currency on $date (Y-m-d), falling back to the most recent
     * rate within the preceding LOOKBACK_DAYS days.
     *
     * @return array{rate: float, rateDate: string}|null
     */
    public function rateFor(User $user, string $currency, string $date): ?array
    {
        $currency = strtoupper($currency);

        $cached = $user->exchangeRates()
            ->where('currency', $currency)
            ->where('date', $date)
            ->first();

        if ($cached) {
            return ['rate' => $cached->rate, 'rateDate' => $cached->rate_date];
        }

        $start = CarbonImmutable::parse($date)->subDays(self::LOOKBACK_DAYS)->toDateString();

        try {
            $response = Http::acceptJson()
                ->timeout(10)
                ->get("{$this->baseUrl}/exchangerates/rates/a/".strtolower($currency)."/{$start}/{$date}/", ['format' => 'json']);
        } catch (Throwable) {
            return null;
        }

        if ($response->failed()) {
            return null;
        }

        $rates = $response->json('rates');
        $last = is_array($rates) ? end($rates) : null;
        if (! is_array($last) || ! isset($last['mid'], $last['effectiveDate'])) {
            return null;
        }

        $entry = ['rate' => (float) $last['mid'], 'rateDate' => (string) $last['effectiveDate']];

        // A fallback rate for today (or later) may still be superseded once NBP
        // publishes today's table (around noon), so only cache final answers.
        $isFinal = $entry['rateDate'] === $date || $date < now()->toDateString();
        if ($isFinal) {
            $user->exchangeRates()->updateOrCreate(
                ['currency' => $currency, 'date' => $date],
                ['rate' => $entry['rate'], 'rate_date' => $entry['rateDate']],
            );
        }

        return $entry;
    }
}
