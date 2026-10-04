<?php

namespace LuisML\AccountsClient\Concerns;


/**
 * The roles Accounts assigns this person in this application. They are copied at sign-in and kept
 * current by the profile synchronization; they are never edited locally.
 *
 * @property list<string>|null $accounts_roles
 */
trait HasAccountsRoles
{
    public function initializeHasAccountsRoles(): void
    {
        $this->casts['accounts_roles'] = 'array';
    }

    /**
     * @return list<string>
     */
    public function accountsRoles(): array
    {
        return array_values((array) ($this->accounts_roles ?? []));
    }

    public function hasAccountsRole(string $role): bool
    {
        return in_array($role, $this->accountsRoles(), true);
    }
}
