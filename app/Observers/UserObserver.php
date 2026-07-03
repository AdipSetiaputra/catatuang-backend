<?php

namespace App\Observers;

use App\Models\User;

class UserObserver
{
    /**
     * Handle the User "created" event.
     * Auto-create default "Cash" wallet for every new user.
     */
    public function created(User $user): void
    {
        $user->wallets()->create([
            'name' => 'Cash',
            'balance' => 0,
            'is_default' => true,
        ]);
    }
}
