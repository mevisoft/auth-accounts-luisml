<?php

namespace LuisML\AccountsClient\Actions;

use RuntimeException;

final class TokenResponse
{
    /**
     * @param  array<string, mixed>  $response
     * @return array{access_token: string, refresh_token: ?string, expires_in: int}
     */
    public static function tokens(array $response, ?string $previousRefreshToken = null): array
    {
        $access = $response['access_token'] ?? null;
        $refresh = $response['refresh_token'] ?? $previousRefreshToken;
        $expires = filter_var($response['expires_in'] ?? null, FILTER_VALIDATE_INT);

        if (! is_string($access) || $access === '' || $expires === false || $expires <= 0
            || ($refresh !== null && (! is_string($refresh) || $refresh === ''))
            || (isset($response['token_type']) && (! is_string($response['token_type']) || strcasecmp($response['token_type'], 'Bearer') !== 0))) {
            throw new RuntimeException('Accounts devolvió una respuesta de tokens inválida.');
        }

        return ['access_token' => $access, 'refresh_token' => $refresh, 'expires_in' => $expires];
    }
}
