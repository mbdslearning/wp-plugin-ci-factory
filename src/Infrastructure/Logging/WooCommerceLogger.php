<?php
declare(strict_types=1);

namespace Vendor\PaymongoCheckout\Infrastructure\Logging;

use Psr\Log\AbstractLogger;
use Vendor\PaymongoCheckout\Support\Strings;

final class WooCommerceLogger extends AbstractLogger implements LoggerInterface
{
    private string $source;

    /** @var callable():bool */
    private $enabledCallback;

    /**
     * @param callable():bool $enabledCallback
     */
    public function __construct(string $source, callable $enabledCallback)
    {
        $this->source = $source;
        $this->enabledCallback = $enabledCallback;
    }

    public function log($level, $message, array $context = []): void
    {
        if (!function_exists('wc_get_logger')) {
            // Fall back to PHP error_log in non-Woo contexts (e.g., early bootstrap).
            error_log('[wc-paymongo-checkout][' . (string) $level . '] ' . (string) $message);
            return;
        }

        $enabled = (bool) ($this->enabledCallback)();
        if (!$enabled) {
            return;
        }

        $sanitized = $this->sanitizeContext($context);

        wc_get_logger()->log(
            (string) $level,
            (string) $message,
            array_merge(['source' => $this->source], $sanitized)
        );
    }

    /**
     * @param array<string,mixed> $context
     * @return array<string,mixed>
     */
    private function sanitizeContext(array $context): array
    {
        $out = $context;

        foreach (['secret', 'secret_key', 'secret_key_test', 'secret_key_live', 'api_key', 'authorization', 'Authorization', 'webhook_secret', 'webhook_secret_test', 'webhook_secret_live'] as $k) {
            if (isset($out[$k]) && is_string($out[$k])) {
                $out[$k] = Strings::maskSecret($out[$k]);
            }
        }

        // Try to sanitize nested arrays lightly.
        array_walk_recursive($out, static function (&$v, $key): void {
            if (is_string($key) && preg_match('/secret|key|token|authorization/i', $key) && is_string($v)) {
                $v = Strings::maskSecret($v);
            }
        });


        if (isset($out['body']) && is_string($out['body'])) {
            $out['body'] = Strings::truncateAndMask($out['body'], 1024);
        }
        return $out;
    }
}
