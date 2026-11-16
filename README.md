# snab — Suro needs a budget

Personal YNAB toolbox built with Laravel 13, Livewire 4 and Flux. It can be installed as a PWA.

**Tools**
- **Conversions:** converts foreign-currency YNAB transactions to PLN using the NBP (National Bank of Poland) mid-rate for each transaction's date. It stamps the memo (`TX 42.50EUR @ 4.2531 (2024-01-15)`) and lets you ignore transactions (`TX ignored`). This is a port of `ynab-euro`.

## Setup

```bash
composer run setup                 # install deps, create .env + SQLite DB, migrate, build assets
# put your YNAB personal access token in .env:  YNAB_TOKEN=...
php artisan snab:user you@example.com   # registration is disabled — create your login
composer run dev                   # http://localhost:8000
```

Then open **Settings → YNAB**:
1. Pick your budget.
2. Enable your foreign-currency accounts and set their currencies.
3. Optionally use **Skip existing** so only new transactions show up.

On a phone, open the site and use **Add to Home Screen** to install it.

## Development

See [AGENTS.md](AGENTS.md) for architecture, domain rules and conventions.

```bash
php artisan test     # Pest suite
composer test        # lint + static analysis + tests
```
