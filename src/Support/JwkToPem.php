<?php

namespace LuisML\AccountsClient\Support;

final class JwkToPem
{
    /**
     * Build a PEM public key from the modulus and exponent of an RSA JWK.
     *
     * @param  array<string, string>  $jwk
     */
    public static function convert(array $jwk): string
    {
        $decode = fn (string $value): string => base64_decode(strtr($value, '-_', '+/').str_repeat('=', (4 - strlen($value) % 4) % 4));
        $length = fn (int $size): string => $size < 128 ? chr($size) : chr(0x80 | strlen(ltrim(pack('N', $size), "\0"))).ltrim(pack('N', $size), "\0");
        $integer = function (string $binary) use ($length): string {
            $binary = ord($binary[0]) > 127 ? "\0".$binary : $binary;

            return "\x02".$length(strlen($binary)).$binary;
        };
        $sequence = fn (string $content): string => "\x30".$length(strlen($content)).$content;

        $rsaKey = $sequence($integer($decode($jwk['n'])).$integer($decode($jwk['e'])));
        $bitString = "\x03".$length(strlen($rsaKey) + 1)."\x00".$rsaKey;
        $algorithm = $sequence("\x06\x09\x2a\x86\x48\x86\xf7\x0d\x01\x01\x01\x05\x00");

        return "-----BEGIN PUBLIC KEY-----\n".chunk_split(base64_encode($sequence($algorithm.$bitString)), 64, "\n")."-----END PUBLIC KEY-----\n";
    }
}
