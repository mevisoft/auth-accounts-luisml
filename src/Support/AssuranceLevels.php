<?php

namespace LuisML\AccountsClient\Support;

/**
 * The authentication levels Accounts publishes as `acr`, from the weakest to the strongest.
 */
final class AssuranceLevels
{
    public const PASSWORD = 'urn:accounts:acr:pwd';

    public const MULTI_FACTOR = 'urn:accounts:acr:mfa';

    public const PHISHING_RESISTANT = 'urn:accounts:acr:phr';

    private const ORDER = [self::PASSWORD, self::MULTI_FACTOR, self::PHISHING_RESISTANT];

    /**
     * Whether a reached level is at least the required one. An unknown level only satisfies itself.
     */
    public static function satisfies(?string $reached, string $required): bool
    {
        if ($reached === null) {
            return false;
        }

        $reachedRank = array_search($reached, self::ORDER, true);
        $requiredRank = array_search($required, self::ORDER, true);

        return $reachedRank === false || $requiredRank === false
            ? $reached === $required
            : $reachedRank >= $requiredRank;
    }
}
