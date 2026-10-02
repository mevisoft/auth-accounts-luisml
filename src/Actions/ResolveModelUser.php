<?php

namespace LuisML\AccountsClient\Actions;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
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

        $user = $model::query()
            ->where('accounts_issuer', $identity->issuer)
            ->where('accounts_sub', $identity->subject)
            ->first() ?? new $model;

        // forceFill: the application's own mass-assignment rules must not drop the link columns.
        $user->forceFill(array_filter([
            'name' => $identity->name ?? $user->name ?? 'Cuenta LuisML',
            'email' => $identity->email,
        ], fn ($value) => $value !== null));

        // Accounts is the authority on email verification: a verified email there is verified here.
        if ($identity->emailVerified && $identity->email !== null && Schema::hasColumn($user->getTable(), 'email_verified_at') && $user->email_verified_at === null) {
            $user->forceFill(['email_verified_at' => now()]);
        }

        if (! $user->exists) {
            $user->forceFill([
                'accounts_issuer' => $identity->issuer,
                'accounts_sub' => $identity->subject,
                'password' => Hash::make(Str::random(64)),
            ]);
        }

        $user->save();

        return $user;
    }
}
