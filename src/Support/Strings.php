<?php
declare(strict_types=1);

namespace Vendor\PaymongoCheckout\Support;

final class Strings
{
    public static function maskSecret(string $value): string
    {
        $len = strlen($value);
        if ($len <= 8) {
            return str_repeat('*', max(0, $len));
        }
        return substr($value, 0, 4) . str_repeat('*', $len - 8) . substr($value, -4);
    }


    public static function truncateAndMask(string $value, int $maxLen = 1024): string
    {
        $value = substr($value, 0, max(0, $maxLen));

        // Best-effort masking for common credential patterns in JSON / headers.
        $value = preg_replace_callback(
            '/("?(?:secret|api[_-]?key|authorization|webhook[_-]?secret)"?\s*[:=]\s*")([^"]+)(")/i',
            static function (array $m): string {
                return $m[1] . self::maskSecret((string) $m[2]) . $m[3];
            },
            $value
        ) ?? $value;

        return $value;
    }
}
