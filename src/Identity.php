<?php

namespace LuisML\AccountsClient;

/**
 * The verified identity delivered by Accounts: only the stable subject and the consented claims.
 */
final readonly class Identity
{
    public function __construct(
        public string $issuer,
        public string $subject,
        public ?string $name,
        public ?string $email,
        public bool $emailVerified,
    ) {}
}
