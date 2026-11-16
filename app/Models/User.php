<?php

namespace App\Models;

use Database\Factories\UserFactory;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Str;
use Laravel\Fortify\Contracts\PasskeyUser;
use Laravel\Fortify\PasskeyAuthenticatable;
use Laravel\Fortify\TwoFactorAuthenticatable;

/**
 * @property int $id
 * @property string $name
 * @property string $email
 * @property Carbon|null $email_verified_at
 * @property string $password
 * @property string|null $two_factor_secret
 * @property string|null $two_factor_recovery_codes
 * @property Carbon|null $two_factor_confirmed_at
 * @property string|null $remember_token
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['name', 'email', 'password'])]
#[Hidden(['password', 'two_factor_secret', 'two_factor_recovery_codes', 'remember_token'])]
class User extends Authenticatable implements PasskeyUser
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable, PasskeyAuthenticatable, TwoFactorAuthenticatable;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
        ];
    }

    /**
     * Get the user's initials
     */
    public function initials(): string
    {
        $initials = Str::initials($this->name, true);

        return Str::length($initials) > 1
            ? Str::substr($initials, 0, 1).Str::substr($initials, -1)
            : $initials;
    }

    /**
     * @return HasMany<YnabAccount, $this>
     */
    public function ynabAccounts(): HasMany
    {
        return $this->hasMany(YnabAccount::class)->withoutGlobalScope('user');
    }

    /**
     * @return HasMany<ExchangeRate, $this>
     */
    public function exchangeRates(): HasMany
    {
        return $this->hasMany(ExchangeRate::class)->withoutGlobalScope('user');
    }

    /**
     * @return HasMany<Setting, $this>
     */
    public function settings(): HasMany
    {
        return $this->hasMany(Setting::class)->withoutGlobalScope('user');
    }

    public function setting(string $key, ?string $default = null): ?string
    {
        return $this->settings()->where('key', $key)->value('value') ?? $default;
    }

    public function setSetting(string $key, ?string $value): void
    {
        $this->settings()->updateOrCreate(['key' => $key], ['value' => $value]);
    }

    /** The user's YNAB personal access token (stored encrypted with APP_KEY). */
    public function ynabToken(): ?string
    {
        $encrypted = $this->setting(Setting::YNAB_TOKEN);
        if ($encrypted === null) {
            return null;
        }

        try {
            return Crypt::decryptString($encrypted);
        } catch (DecryptException) {
            return null; // e.g. APP_KEY rotated — the user has to enter the token again
        }
    }

    public function setYnabToken(?string $token): void
    {
        if (blank($token)) {
            $this->settings()->where('key', Setting::YNAB_TOKEN)->delete();

            return;
        }

        $this->setSetting(Setting::YNAB_TOKEN, Crypt::encryptString(trim($token)));
    }

    public function hasYnabToken(): bool
    {
        return $this->ynabToken() !== null;
    }

    public function ynabPlanId(): string
    {
        return $this->setting(Setting::YNAB_PLAN_ID) ?? 'last-used';
    }
}
