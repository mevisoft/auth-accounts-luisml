<?php

namespace LuisML\AccountsClient;

use Illuminate\Contracts\Session\Session;
use Illuminate\Support\Facades\Crypt;

/**
 * The server-side record of one browser's access: tokens (encrypted), validity horizons and activity
 * bookkeeping. Tokens never reach props, JavaScript or logs.
 */
final class AccountsSession
{
    private const KEY = 'accounts.session';

    public function __construct(private Session $session) {}

    public static function for(Session $session): self
    {
        return new self($session);
    }

    /**
     * @param  array{access_token: string, refresh_token: ?string, expires_in: int}  $tokens
     */
    public function store(array $tokens, int $validatedUntil, ?int $sessionExpiresAt, string $subject): void
    {
        $this->session->put(self::KEY, [
            'subject' => $subject,
            'access_token' => Crypt::encryptString($tokens['access_token']),
            'refresh_token' => $tokens['refresh_token'] === null ? null : Crypt::encryptString($tokens['refresh_token']),
            'access_expires_at' => now()->getTimestamp() + $tokens['expires_in'],
            'validated_until' => $validatedUntil,
            'session_expires_at' => $sessionExpiresAt,
            'last_report_at' => 0,
        ]);
    }

    public function exists(): bool
    {
        return is_array($this->session->get(self::KEY));
    }

    public function forget(): void
    {
        $this->session->forget(self::KEY);
    }

    public function accessToken(): ?string
    {
        return $this->decrypt('access_token');
    }

    public function refreshToken(): ?string
    {
        return $this->decrypt('refresh_token');
    }

    public function accessExpired(): bool
    {
        return $this->value('access_expires_at', 0) <= now()->getTimestamp();
    }

    public function validatedUntil(): int
    {
        return (int) $this->value('validated_until', 0);
    }

    public function sessionExpiresAt(): ?int
    {
        $value = $this->value('session_expires_at');

        return $value === null ? null : (int) $value;
    }

    public function lastReportAt(): int
    {
        return (int) $this->value('last_report_at', 0);
    }

    public function markReported(): void
    {
        $this->update(['last_report_at' => now()->getTimestamp()]);
    }

    /**
     * Record a successful validation. The horizon is the start of the query plus the allowed window,
     * bounded by the access token and by the central session; a slow answer never widens it.
     */
    public function validated(int $startedAt, ?int $sessionExpiresAt): void
    {
        $horizon = $startedAt + (int) config('accounts.validation_seconds');

        foreach ([$this->value('access_expires_at'), $sessionExpiresAt] as $limit) {
            if ($limit !== null) {
                $horizon = min($horizon, (int) $limit);
            }
        }

        $this->update(['validated_until' => $horizon, 'session_expires_at' => $sessionExpiresAt]);
    }

    /**
     * @param  array{access_token: string, refresh_token: ?string, expires_in: int}  $tokens
     */
    public function rotate(array $tokens): void
    {
        $this->update([
            'access_token' => Crypt::encryptString($tokens['access_token']),
            'refresh_token' => $tokens['refresh_token'] === null ? null : Crypt::encryptString($tokens['refresh_token']),
            'access_expires_at' => now()->getTimestamp() + $tokens['expires_in'],
            'validated_until' => 0,
        ]);
    }

    private function decrypt(string $key): ?string
    {
        $value = $this->value($key);

        return is_string($value) ? Crypt::decryptString($value) : null;
    }

    private function value(string $key, mixed $default = null): mixed
    {
        return ($this->session->get(self::KEY) ?? [])[$key] ?? $default;
    }

    /**
     * @param  array<string, mixed>  $changes
     */
    private function update(array $changes): void
    {
        $this->session->put(self::KEY, [...($this->session->get(self::KEY) ?? []), ...$changes]);
    }
}
