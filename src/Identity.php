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
        /** @var list<string> the roles Accounts assigns in this application */
        public array $roles = [],
    ) {}

    /**
     * Map UserInfo consistently at login and synchronization. Only a JSON boolean may verify
     * an email, and only a JSON array may grant roles.
     *
     * @param  array<string, mixed>  $profile
     */
    public static function fromUserInfo(string $issuer, string $subject, array $profile): self
    {
        return new self(
            issuer: $issuer,
            subject: $subject,
            name: is_string($profile['name'] ?? null) ? $profile['name'] : null,
            email: is_string($profile['email'] ?? null) ? $profile['email'] : null,
            emailVerified: ($profile['email_verified'] ?? false) === true,
            roles: is_array($profile['roles'] ?? null) ? array_values(array_filter($profile['roles'], 'is_string')) : [],
        );
    }
}
