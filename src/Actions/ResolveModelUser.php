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

        $user = $model::query()
            ->where('accounts_issuer', $identity->issuer)
            ->where('accounts_sub', $identity->subject)
            ->first() ?? new $model;

        $schema = $user->getConnection()->getSchemaBuilder();

        // forceFill: the application's own mass-assignment rules must not drop the link columns.
        $user->forceFill(array_filter([
            'name' => $identity->name ?? $user->name ?? 'Cuenta LuisML',
            'email' => $identity->email,
        ], fn ($value) => $value !== null));

        // Roles are assigned in Accounts; a missing list means none, so a revoked role never lingers.
        if ($schema->hasColumn($user->getTable(), 'accounts_roles')) {
            // Stored as JSON by hand when the model does not cast the column (no HasAccountsRoles trait).
            $user->forceFill(['accounts_roles' => $user->hasCast('accounts_roles') ? $identity->roles : json_encode($identity->roles)]);
        }

        // Accounts is the authority on email verification: a verified email there is verified here.
        if ($schema->hasColumn($user->getTable(), 'email_verified_at')) {
            $verifiedAt = $identity->emailVerified && $identity->email !== null
                ? ($user->isDirty('email') ? now() : ($user->email_verified_at ?? now()))
                : null;
            $user->forceFill(['email_verified_at' => $verifiedAt]);
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
