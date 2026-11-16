<?php

namespace App\Providers;

use App\Services\CardImport\ClaudeTransactionExtractor;
use App\Services\CardImport\TransactionExtractor;
use App\Services\Rates\NbpRateService;
use App\Services\Ynab\YnabClientFactory;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(YnabClientFactory::class, fn (): YnabClientFactory => new YnabClientFactory(
            config('services.ynab.base_url'),
        ));

        $this->app->singleton(NbpRateService::class, fn (): NbpRateService => new NbpRateService(
            config('services.nbp.base_url'),
        ));

        $this->app->bind(TransactionExtractor::class, ClaudeTransactionExtractor::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureDefaults();
    }

    /**
     * Configure default behaviors for production-ready applications.
     */
    protected function configureDefaults(): void
    {
        Date::use(CarbonImmutable::class);

        DB::prohibitDestructiveCommands(
            app()->isProduction(),
        );

        Password::defaults(fn (): ?Password => app()->isProduction()
            ? Password::min(12)
                ->mixedCase()
                ->letters()
                ->numbers()
                ->symbols()
                ->uncompromised()
            : null,
        );
    }
}
