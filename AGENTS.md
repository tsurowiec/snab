# AGENTS.md

Guidance for AI coding agents (and humans) working in this repository.

**snab** ("Suro needs a budget") is a personal Laravel app hosting small YNAB budgeting tools.
The first tool, **Conversions**, is a port of `../fintex/ynab-euro` (Node/Hono + React PWA):
it converts foreign-currency YNAB transactions to PLN using NBP exchange rates.

## Stack

- Laravel 13, PHP 8.3+, SQLite (`database/database.sqlite`)
- Livewire 4 single-file components + Flux UI + Tailwind 4 (Laravel Livewire starter kit)
- Fortify auth: login, password reset, 2FA, passkeys, password confirmation. **Registration and email verification are disabled.**
- Pest 5 for tests, Pint for style, Larastan (level 7) for types
- Installable PWA (`public/manifest.webmanifest`, `public/sw.js`), served from the domain root

## Commands

```bash
composer run setup            # first-time install (composer, .env, key, migrate, npm, build)
composer run dev              # dev server + queue + logs + vite
php artisan test              # run the Pest suite (or ./vendor/bin/pest)
composer test                 # pint --test + phpstan + tests (what CI should run)
./vendor/bin/pint             # fix code style
./vendor/bin/phpstan analyse  # static analysis
npm run build                 # build assets (needed after JS/CSS changes when not running vite)
php artisan snab:user you@example.com   # create a user / reset a password (no public registration)
```

Always run `php artisan test` and `./vendor/bin/phpstan analyse` before finishing a change.

## Configuration (`.env`)

| Key | Purpose |
|---|---|
| `YNAB_TOKEN` | YNAB personal access token (server-side only, never sent to the browser). Shared by all users for now. |
| `YNAB_BASE_URL` / `NBP_BASE_URL` | Optional API overrides (default `https://api.ynab.com/v1`, `https://api.nbp.pl/api`). |

Everything else (selected budget, accounts and currencies, caches) lives in the DB and is managed in the GUI under
**Settings → YNAB** (`/settings/ynab`). There are deliberately **no CLI tools** besides `snab:user`. The old ynab-euro
CLI commands are buttons there:

| ynab-euro CLI | snab GUI |
|---|---|
| `list-plans` | Settings → YNAB → Budget dropdown (stored as the `ynab_plan_id` setting, default `last-used`) |
| `list-accounts` + `YNAB_ACCOUNTS` env | Settings → YNAB → Foreign-currency accounts (enable toggle + currency) |
| `init-cache` | Settings → YNAB → Caches → "Skip existing" (per account or all) |
| delete `.cache/` | "Reset" (server_knowledge), "Clear rate cache", "Refresh account names" |
| `exchange` / web UI | Tools → Conversions |

## Multi-user rule: every table has `user_id`

Registration is off, but the app may get more users later, so **every app table carries a `user_id`**
(`foreignId('user_id')->constrained()->cascadeOnDelete()`, plus it's part of any natural unique key).

- Models use `App\Models\Concerns\BelongsToUser`. It adds a global scope (`user`) that limits queries to `Auth::id()`
  when someone is logged in, fills `user_id` on create, and provides a `user()` relation.
- Outside an authenticated request (console, queue) the scope does nothing, so go through the owner:
  `$user->ynabAccounts()`, `$user->exchangeRates()`, `$user->settings()`. These relations drop the global scope on purpose.
- Services take the `User` / owning model explicitly. They never call `auth()`.
- Route model binding runs through the scope, so another user's record returns **404**. Policies
  (`app/Policies`) add an explicit check in components (`$this->authorize(...)`).
- Per-user settings: `$user->setting($key, $default)`, `$user->setSetting($key, $value)`, keys as constants on `Setting`.
- New tables must follow the same rule. Add an isolation test to `tests/Feature/UserIsolationTest.php`.

## Architecture

```
app/
  Models/
    Concerns/BelongsToUser.php     user scoping trait (see above)
    User.php                       relations + setting()/setSetting()/ynabPlanId()
    YnabAccount.php                enabled foreign-currency accounts (+ server_knowledge for delta sync)
    ExchangeRate.php               cached NBP rates
    Setting.php                    per-user key/value
  Services/
    Ynab/YnabClient.php            YNAB REST wrapper (Http facade); throws YnabException
    Rates/NbpRateService.php       NBP table A mid-rate per date, DB-cached
    Conversions/
      PendingFilter.php            which transactions still need processing
      ConversionService.php        pending() / pendingCount() / apply(): the one shared implementation
      PendingTransaction.php, ConversionResult.php   DTOs
  Console/Commands/CreateUser.php  snab:user
resources/views/pages/
  tools/conversions/⚡index.blade.php   account list (route conversions.index, PWA start_url)
  tools/conversions/⚡show.blade.php    transaction picker + confirm modal (route conversions.show)
  settings/⚡ynab.blade.php              Settings → YNAB
resources/js/tools/conversions.js       Alpine component: selection, totals, swipe-to-toggle
```

`YnabClient` and `NbpRateService` are singletons bound in `AppServiceProvider` from `config/services.php`.
Livewire components resolve services with `app(...)` and catch `YnabException` to show errors inline.

## Conversions: domain rules (keep these when changing anything)

- YNAB amounts are **milliunits** (int; divide by 1000 to display).
- A transaction is **pending** unless:
  - it is deleted;
  - its memo contains `TX`;
  - it is the unapproved half of a match (`matched_transaction_id` set and `approved` false).
- Rates are **per transaction date**: the NBP mid-rate for that date, or the latest one in the preceding **7 days**
  (weekends and holidays). Transactions with no rate are left out of the list.
- **Convert:**
  - `amount = floor(amount * rate + 0.5)`, the same as JS `Math.round`, so negative values match ynab-euro.
  - The memo gets `TX 42.50EUR @ 4.2531 (2024-01-15)` appended, with a space if a memo already exists. The amount is the original value with 2 decimals; the rate has 4 decimals; the date is the NBP table date.
- **Ignore:** the amount is unchanged and the memo gets `TX ignored` appended.
- **Delta sync:** transactions are fetched with `last_knowledge_of_server = account.server_knowledge`. After a successful
  (non-dry-run) `apply()` the new `server_knowledge` is stored. A dry run neither PATCHes nor stores anything.
- **Rate cache:** rates are cached in `exchange_rates`. A fallback rate for today or a future date is *not* cached, because NBP publishes
  the day's table around noon.

## Adding a tool

1. Put the logic in `app/Services/<Tool>/` (plain classes, `User` passed in, no `auth()`).
2. Add tables with `user_id` + `BelongsToUser` (see the rule above), plus factories.
3. Put pages in `resources/views/pages/tools/<tool>/⚡*.blade.php` and register them in `routes/web.php` inside the
   `auth` group: `Route::livewire('<tool>', 'pages::tools.<tool>.index')->name('<tool>.index')`.
4. Add a nav item to the "Tools" group in `resources/views/layouts/app/sidebar.blade.php` and a card on
   `resources/views/dashboard.blade.php`.
5. Put tool settings on their own settings page (`resources/views/pages/settings/`, linked in `pages/settings/layout.blade.php`)
   or on Settings → YNAB if they're YNAB-related. Make them GUI actions, not artisan commands.
6. Larger client-side behaviour goes in `resources/js/tools/<tool>.js`, registered as `Alpine.data` in `resources/js/app.js`.

## Testing conventions

- Pest function syntax for new tests. The kit's original tests are PHPUnit classes and run under Pest unchanged.
- `tests/Pest.php` applies `RefreshDatabase` to `Feature` and `Unit`, calls `Http::preventStrayRequests()`, and sets a
  fake YNAB token.
- Use the `fakeApis(transactions:, serverKnowledge:, nbpRates:, accounts:, plans:)` helper to fake YNAB and NBP together.
  Use `ynabTx([...])` to build a YNAB transaction array.
- For lazy Livewire pages, call `Livewire::withoutLazyLoading()` before `Livewire::test('pages::...')`.
- Assert the outgoing PATCH payload (`Http::assertSent`) for anything that writes to YNAB.
