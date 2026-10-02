<?php

namespace LuisML\AccountsClient\Actions;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use LuisML\AccountsClient\Identity;

class ResolveModelUser
{
    /**
     * Find or create the local user for (issuer, sub). The email is only copied, never used to
     * link identities, and the local password is random and unusable: credentials live in Accounts.
     */
    public function __invoke(Identity $identity): ?Authenticatable
    {
        $model = (string) config('accounts.user_model');

        $user = $model::query()->firstOrNew([
            'accounts_issuer' => $identity->issuer,
            'accounts_sub' => $identity->subject,
        ]);

        $user->forceFill(array_filter([
            'name' => $identity->name ?? $user->name ?? 'Cuenta LuisML',
            'email' => $identity->email,
        ], fn ($value) => $value !== null));

        if (! $user->exists) {
            $user->forceFill(['password' => Hash::make(Str::random(64))]);
        }

        $user->save();

        return $user;
    }
}
