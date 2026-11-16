<?php

namespace App\Console\Commands;

use App\Concerns\ProfileValidationRules;
use App\Models\User;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password;

use function Laravel\Prompts\password;
use function Laravel\Prompts\text;

/**
 * Registration is disabled — users are created (or their password reset) here.
 */
#[Signature('snab:user {email : Login e-mail} {--name= : Display name} {--password= : Password (prompted when omitted)}')]
#[Description('Create a snab user or reset an existing user\'s password')]
class CreateUser extends Command
{
    use ProfileValidationRules;

    public function handle(): int
    {
        $email = strtolower((string) $this->argument('email'));
        $existing = User::where('email', $email)->first();

        $name = $this->option('name')
            ?? ($existing ? $existing->name : text('Name', required: true));

        $plain = $this->option('password')
            ?? password('Password', required: true, hint: $existing ? 'Resets the existing password' : '');

        $validator = Validator::make(
            ['email' => $email, 'name' => $name, 'password' => $plain],
            [...$this->profileRules($existing?->id), 'password' => ['required', 'string', Password::defaults()]],
        );

        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $error) {
                $this->components->error($error);
            }

            return self::FAILURE;
        }

        $user = User::updateOrCreate(['email' => $email], ['name' => $name, 'password' => $plain]);

        $this->components->info(($user->wasRecentlyCreated ? 'Created' : 'Updated')." user {$user->email}.");

        return self::SUCCESS;
    }
}
