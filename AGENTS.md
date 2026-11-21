# AGENTS.md

Guidance for AI coding agents (and humans) working in this repository.

**snab** ("Suro needs a budget") is a personal Laravel app hosting small YNAB budgeting tools:

- **Conversions** is a port of `../fintex/ynab-euro` (Node/Hono + React PWA). It converts foreign-currency YNAB
  transactions to PLN using NBP exchange rates.
- **Card import** reads Revolut credit-card transactions from screenshots with Claude (Anthropic PHP SDK). It stores
  each one once and sends it to a YNAB account.
- **Alior payees** fixes the payees that Alior's own bank sync gets wrong ("Transakcja kartą", another merchant). Claude reads
  Alior screenshots, the rows are matched to YNAB by amount + date, and the payees are renamed. Transactions missing from YNAB are added.

## Stack

- Laravel 13, PHP 8.4.1+ (CI and dev run 8.5), SQLite (`database/database.sqlite`)
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
./vendor/bin/phpstan analyse --memory-limit=1G  # static analysis
npm run build                 # build assets (needed after JS/CSS changes when not running vite)
php artisan snab:user you@example.com   # create a user / reset a password (no public registration)
```

Always run `composer test` (or at least `php artisan test` and phpstan) before finishing a change.

## Configuration (`.env`)

| Key | Purpose |
|---|---|
| `APP_KEY` | Also encrypts the users' YNAB tokens. If you rotate it, every user has to re-enter their token. |
| `YNAB_BASE_URL` / `NBP_BASE_URL` | Optional API overrides (default `https://api.ynab.com/v1`, `https://api.nbp.pl/api`). |

The **Anthropic API key** is per user too, set in **Settings → AI** (`/settings/ai`).
All other per-user config lives in the DB and is managed in the GUI under **Settings → YNAB** (`/settings/ynab`):
the **YNAB personal access token**, the selected budget, accounts and currencies, and the caches. There are deliberately **no CLI tools** besides `snab:user`. The old ynab-euro
CLI commands are buttons there:

| ynab-euro CLI | snab GUI |
|---|---|
| `YNAB_TOKEN` env | Settings → YNAB → Personal access token (checked against YNAB, then stored encrypted per user) |
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
- **The YNAB token belongs to the user.** Read and write it only through `$user->ynabToken()`, `setYnabToken()` and `hasYnabToken()`.
  It is stored with `Crypt::encryptString` under `Setting::YNAB_TOKEN`. Never put it in a public Livewire property, a view or a log.
  Always get a client with `app(YnabClientFactory::class)->forUser($user)`. There is no app-wide token or client.
- **The Anthropic API key follows the same rule.** Use `$user->anthropicApiKey()`, `setAnthropicApiKey()` and
  `hasAnthropicApiKey()`, stored under `Setting::ANTHROPIC_API_KEY`. Both secrets use the private `encryptedSetting()` helpers on `User`.
- New tables must follow the same rule. Add an isolation test to `tests/Feature/UserIsolationTest.php`.

## Architecture

```
app/
  Models/
    Concerns/BelongsToUser.php     user scoping trait (see above)
    User.php                       relations + setting()/setSetting()/ynabToken()/setYnabToken()/ynabPlanId()
    YnabAccount.php                enabled foreign-currency accounts (+ server_knowledge for delta sync)
    ExchangeRate.php               cached NBP rates
    CardTransaction.php            card transactions from screenshots; hashFor() = dedup key
    LlmUsage.php                   one row per Claude call: tokens + cost_micros (USD × 1e6)
    Setting.php                    per-user key/value
  Services/
    Ynab/YnabClient.php            YNAB REST wrapper (Http facade), one per token; throws YnabException
    Ynab/YnabClientFactory.php     forUser($user) / withToken($token)
    Rates/NbpRateService.php       NBP table A mid-rate per date, DB-cached
    Conversions/
      PendingFilter.php            which transactions still need processing
      ConversionService.php        pending() / pendingCount() / apply(): the one shared implementation
      PendingTransaction.php, ConversionResult.php   DTOs
    CardImport/
      TransactionExtractor.php     interface (bound to ClaudeTransactionExtractor; tests bind a fake)
      ClaudeTransactionExtractor.php  screenshot → Claude (claude-sonnet-5-5, beta endpoint + server-side fallback, strict JSON schema) → parse()
      ExtractedTransaction.php     DTO, hash()
      CardImportService.php        preview() flags duplicates, store() inserts new rows (insertOrIgnore)
      YnabExporter.php             export(): POSTs status=new rows to the chosen YNAB account and marks them recorded
    AliorPayees/
      PayeeExtractor.php           interface (bound to ClaudePayeeExtractor; tests bind a fake)
      ClaudePayeeExtractor.php     Alior screenshot → {date, merchant, amount} → parse()
      AliorRow.php                 DTO, normalize(), importId()
      AliorPayeeService.php        preview() matches to YNAB, apply() PATCHes payees + POSTs missing rows
    Anthropic/ScreenshotReader.php shared Claude screenshot call (beta + fallback, strict schema, usage, errors)
    Anthropic/AnthropicKeyVerifier.php   checks a key (models list) before it's saved
    Anthropic/Pricing.php          list prices per model ($/MTok) → cost in micro-dollars
    Anthropic/UsageRecorder.php    stores an llm_usages row per Claude response (prices fallback iterations per model)
  Console/Commands/CreateUser.php  snab:user
resources/views/pages/
  tools/conversions/⚡index.blade.php   account list (route conversions.index)
  tools/conversions/⚡show.blade.php    transaction picker + confirm modal (route conversions.show)
  tools/card-import/⚡index.blade.php   upload → review → save, plus the imported list
  tools/alior-payees/⚡index.blade.php  upload → review (rename / add / OK) → apply, stateless
  settings/⚡ynab.blade.php              Settings → YNAB
  settings/⚡ai.blade.php                Settings → AI (Anthropic key)
resources/js/tools/conversions.js       Alpine component: selection, totals, swipe-to-toggle
resources/js/tools/card-import.js       Alpine component: paste screenshots (⌘V / paste box / Clipboard API) → $wire.uploadMultiple → extract
```

`YnabClientFactory` and `NbpRateService` are singletons bound in `AppServiceProvider` from `config/services.php`.
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

## Card import: domain rules

- A screenshot shows date headers ("Today", "Yesterday", "12 Nov"). Under each header are rows with the merchant,
  the time and the amount. Claude resolves the headers to dates using today's date, which is sent in the prompt.
  A header without a year means its most recent past occurrence.
- Amounts are **milliunits**, signed like YNAB: spending is negative.
- **The card is always in PLN** (`ExtractedTransaction::ACCOUNT_CURRENCY`), so the main amount is PLN.
  `original_amount` and `original_currency` hold the foreign amount on foreign purchases. `parse()` swaps the two
  if Claude returns them the wrong way round. A row that still isn't in PLN is flagged in the review and not
  selected by default.
- **Dedup hash** = `sha256("Y-m-d H:i|amount|CURRENCY|normalized description")`. The description is lowercased,
  trimmed and has its whitespace collapsed. The hash is unique per `(user_id, hash)`. A duplicate in the review is
  shown as "already imported" and can't be selected.
- Declined and reverted rows are shown in the review but not selected by default.
- Screenshots are never stored; only the extracted rows are.
- **YNAB export:**
  - **When:** Save stores the rows and then calls `YnabExporter::export()`, but only if the user has a YNAB token
    and a target account (`Setting::CARD_IMPORT_YNAB_ACCOUNT`, chosen in Settings → YNAB).
  - **What it sends:** every `status = new` row, as an unapproved and uncleared transaction. Payee = description,
    memo left empty.
  - **Dedup:** `import_id = "SNAB:" + first 31 chars of the hash`. YNAB refuses duplicates per account; its
    `duplicate_import_ids` are marked `recorded` too.
  - **After sending:** rows become `recorded`, with `ynab_transaction_id` set.
  - **On failure:** rows stay `new`, and the page shows the error with "Retry sending" and "Send N to YNAB".

## Alior payees: domain rules

- **Stateless**: no table. Every review is checked against live YNAB, so re-running is safe.
- **Target account**: `Setting::ALIOR_YNAB_ACCOUNT`, chosen in Settings → YNAB. Transactions are fetched with
  `since_date` = earliest screenshot date − 3 days.
- **Not matched against**: deleted transactions, transfers, and the unapproved half of a match.
- **Matching** is one-to-one, by **equal amount**:
  - pass 1 takes the exact date (preferring a transaction whose payee already matches);
  - pass 2 takes the closest date within ±3 days (`DATE_TOLERANCE_DAYS`), shown as "YNAB date …";
  - a fallback never takes a transaction that another row matches exactly.
- **Actions**:
  - `rename`: the payee differs (normalized: lowercased, whitespace collapsed);
  - `ok`: same payee, not selectable;
  - `create`: no match.
- **Rename** PATCHes only `payee_id: null` + `payee_name`. YNAB resolves `payee_name` only when `payee_id` is null.
  Memo, approval and cleared are untouched.
- **Create**:
  - The new transaction is unapproved, uncleared, with an empty memo.
  - `import_id = "SNAB:A:" + first 29 chars of sha256(date|amount|normalized merchant)`. A repeated identical row gets a `:N` suffix.
  - YNAB's `duplicate_import_ids` are reported as "already in YNAB".
- **Overlapping screenshots**: each distinct row is kept as many times as the screenshot with the most copies of it shows it,
  so two equal purchases on one day survive.

## Claude usage tracking

- **Every** Claude call goes through `UsageRecorder::record()` right after the response, before stop_reason or
  parse checks, because refusals and truncated replies are billed too.
- Costs are estimates based on list prices in `Pricing::MODELS`. Update that table when prices or models change;
  an unknown model gets `cost_micros = null`, which is shown as "—".
- Settings → AI shows this month's total, the all-time total and the last 10 calls. Card import shows the cost
  of each extraction.

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
- `tests/Pest.php` applies `RefreshDatabase` to `Feature` and `Unit` and calls `Http::preventStrayRequests()`.
- Users that talk to YNAB need a token: `User::factory()->withYnabToken('optional-token')`. `YnabAccount::factory()` creates
  its user with a token already.
- Use the `fakeApis(transactions:, serverKnowledge:, nbpRates:, accounts:, plans:)` helper to fake YNAB and NBP together.
  Use `ynabTx([...])` to build a YNAB transaction array.
- For lazy Livewire pages, call `Livewire::withoutLazyLoading()` before **each** `Livewire::test('pages::...')`. It only
  applies to the next call; without it you only get the placeholder and no YNAB requests are made.
- Card import: bind a fake with `app()->instance(TransactionExtractor::class, ...)` (see `CardImportPageTest`).
  `ClaudeTransactionExtractor` takes a PSR-18 transporter, so a Guzzle `MockHandler` can check the real SDK request.
  The Anthropic SDK doesn't use the `Http` facade.
- PHPStan needs `--memory-limit=1G` because of the Anthropic SDK. `composer test` already passes it.
- Assert the outgoing PATCH payload (`Http::assertSent`) for anything that writes to YNAB.
