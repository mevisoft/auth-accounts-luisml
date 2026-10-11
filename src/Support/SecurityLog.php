<?php

namespace LuisML\AccountsClient\Support;

use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * What only the application knows about a sign-in or a sign-out, recorded for whoever audits it: the
 * account (its public identifier), the application, the operation, the result and an identifier that
 * ties the entries of one request together. Never an email, a name or a token.
 */
final class SecurityLog
{
    public static function warning(string $event, string $operation, string $result, ?string $subject): void
    {
        self::write('warning', $event, $operation, $result, $subject);
    }

    public static function info(string $event, string $operation, string $result, ?string $subject): void
    {
        self::write('info', $event, $operation, $result, $subject);
    }

    private static function write(string $level, string $event, string $operation, string $result, ?string $subject): void
    {
        $context = [
            'account' => $subject,
            'client_id' => config('accounts.client_id'),
            'operation' => $operation,
            'result' => $result,
            'correlation_id' => Context::remember('correlation_id', fn (): string => (string) Str::uuid()),
        ];

        $channel = config('accounts.log_channel');

        if (is_string($channel) && $channel !== '') {
            Log::channel($channel)->{$level}($event, $context);

            return;
        }

        Log::{$level}($event, $context);
    }
}
