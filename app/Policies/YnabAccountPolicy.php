<?php

namespace App\Policies;

use App\Models\User;
use App\Models\YnabAccount;

class YnabAccountPolicy
{
    public function view(User $user, YnabAccount $account): bool
    {
        return $account->user_id === $user->id;
    }

    public function update(User $user, YnabAccount $account): bool
    {
        return $account->user_id === $user->id;
    }
}
