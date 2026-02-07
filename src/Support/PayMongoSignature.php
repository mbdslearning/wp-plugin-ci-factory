<?php
declare(strict_types=1);

namespace Vendor\PaymongoCheckout\Support;

use WP_Error;

final class PayMongoSignature
{
    /**
     * Verify PayMongo webhook signature header.
     *
     * Supported header:
     *   Paymongo-Signature: t=timestamp, te=test_signature, li=live_signature
     * expected = HMAC_SHA256( "{t}.{raw_body}", webhook_secret )
     *
     * @return true|WP_Error
     */
    public function verify(string $rawBody, bool $livemode, string $webhookSecret, int $maxSkewSeconds = 300, ?string $signatureHeader = null)
    {
        if ($webhookSecret === '') {
            return new WP_Error('missing_webhook_secret', 'Webhook secret not configured for this mode');
        }

        $header = is_string($signatureHeader) ? trim($signatureHeader) : '';

        if ($header === '') {
            // Back-compat fallback: allow reading from server globals if the caller cannot access headers.
            if (isset($_SERVER['HTTP_PAYMONGO_SIGNATURE'])) {
                $header = (string) $_SERVER['HTTP_PAYMONGO_SIGNATURE'];
            } elseif (isset($_SERVER['HTTP_PAYMONGO-SIGNATURE'])) {
                // Some servers may pass it through differently; keep as a fallback.
                $header = (string) $_SERVER['HTTP_PAYMONGO-SIGNATURE'];
            }
        }

        if ($header === '') {
            return new WP_Error('missing_signature', 'Missing Paymongo-Signature header');
        }

        $parts = [];
        foreach (explode(',', $header) as $pair) {
            $pair = trim($pair);
            if (strpos($pair, '=') === false) {
                continue;
            }
            [$k, $v] = array_map('trim', explode('=', $pair, 2));
            if ($k !== '') {
                $parts[$k] = $v;
            }
        }

        $timestamp = isset($parts['t']) ? (int) $parts['t'] : 0;
        $sigTest   = isset($parts['te']) ? (string) $parts['te'] : '';
        $sigLive   = isset($parts['li']) ? (string) $parts['li'] : '';

        if ($timestamp <= 0) {
            return new WP_Error('bad_signature', 'Invalid signature timestamp');
        }

        $now = time();
        if (abs($now - $timestamp) > $maxSkewSeconds) {
            return new WP_Error('stale_signature', 'Stale signature timestamp');
        }

        $expected = hash_hmac('sha256', $timestamp . '.' . $rawBody, $webhookSecret);
        $provided = $livemode ? $sigLive : $sigTest;

        if ($provided === '') {
            return new WP_Error('bad_signature', 'Missing signature for this mode (li/te)');
        }

        if (!hash_equals($expected, $provided)) {
            return new WP_Error('bad_signature', 'Signature mismatch');
        }

        return true;
    }
}
