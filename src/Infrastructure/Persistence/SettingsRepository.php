<?php
declare(strict_types=1);

namespace Vendor\PaymongoCheckout\Infrastructure\Persistence;

final class SettingsRepository
{
    private const OPTION_KEY = 'woocommerce_paymongo_checkout_settings';

    /** @var array<string,mixed>|null */
    private ?array $cached = null;

    /**
     * @return array<string,mixed>
     */
    public function all(): array
    {
        if ($this->cached !== null) {
            return $this->cached;
        }

        $raw = get_option(self::OPTION_KEY, []);
        $this->cached = is_array($raw) ? $raw : [];
        return $this->cached;
    }

    public function getString(string $key, string $default = ''): string
    {
        $const = $this->constantForKey($key);
        if ($const !== '' && defined($const)) {
            $val = constant($const);
            if (is_string($val) && $val !== '') {
                return $val;
            }
        }

        $all = $this->all();
        return isset($all[$key]) ? (string) $all[$key] : $default;
    }

    private function constantForKey(string $key): string
    {
        switch ($key) {
            case 'secret_key_test':
                return 'WC_PAYMONGO_SECRET_KEY_TEST';
            case 'secret_key_live':
                return 'WC_PAYMONGO_SECRET_KEY_LIVE';
            case 'webhook_secret_test':
                return 'WC_PAYMONGO_WEBHOOK_SECRET_TEST';
            case 'webhook_secret_live':
                return 'WC_PAYMONGO_WEBHOOK_SECRET_LIVE';
            default:
                return '';
        }
    }

    public function getBoolYesNo(string $key, bool $default = false): bool
    {
        $all = $this->all();
        if (!isset($all[$key])) {
            return $default;
        }
        return (string) $all[$key] === 'yes';
    }

    /**
     * @return array<int,string>
     */
    public function getArrayStrings(string $key, array $default = []): array
    {
        $all = $this->all();
        $val = $all[$key] ?? $default;
        if (!is_array($val)) {
            return $default;
        }
        $val = array_values(array_unique(array_filter(array_map('strval', $val))));
        return $val ?: $default;
    }
}
