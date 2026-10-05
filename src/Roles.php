<?php

namespace LuisML\AccountsClient;

/**
 * The roles every application can receive from Accounts. They are shared and fixed there; an
 * application may also offer roles of its own, which this package reads as plain strings.
 */
final class Roles
{
    public const OWNER = 'owner';

    public const ADMIN = 'admin';

    public const MEMBER = 'member';
}
