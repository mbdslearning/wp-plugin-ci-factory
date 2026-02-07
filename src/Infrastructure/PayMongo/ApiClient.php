<?php
declare(strict_types=1);

namespace Vendor\PaymongoCheckout\Infrastructure\PayMongo;

use WP_Error;
use Vendor\PaymongoCheckout\Infrastructure\Logging\LoggerInterface;

final class ApiClient
{
    private string $secretKey;
    private LoggerInterface $logger;

    public function __construct(string $secretKey, LoggerInterface $logger)
    {
        $this->secretKey = $secretKey;
        $this->logger = $logger;
    }

    /**
     * @param array<string,mixed> $attributes
     * @return array<string,mixed>|WP_Error
     */
    public function createCheckoutSession(array $attributes, string $idempotencyKey = '')
    {
        $headers = [];
        if ($idempotencyKey !== '') {
            $headers['Idempotency-Key'] = $idempotencyKey;
        }

        $payload = [
            'data' => [
                'attributes' => $attributes,
            ],
        ];

        return $this->request('POST', 'https://api.paymongo.com/v1/checkout_sessions', $payload, $headers);
    }

    /**
     * @return array<string,mixed>|WP_Error
     */
    public function retrieveCheckoutSession(string $checkoutSessionId)
    {
        if ($checkoutSessionId === '') {
            return new WP_Error('missing_session_id', 'Missing checkout session id.');
        }

        $url = 'https://api.paymongo.com/v1/checkout_sessions/' . rawurlencode($checkoutSessionId);
        return $this->request('GET', $url, null, []);
    }

    /**
     * @return array<string,mixed>|WP_Error
     */
    public function expireCheckoutSession(string $checkoutSessionId)
    {
        if ($checkoutSessionId === '') {
            return new WP_Error('missing_session_id', 'Missing checkout session id.');
        }

        $url = 'https://api.paymongo.com/v1/checkout_sessions/' . rawurlencode($checkoutSessionId) . '/expire';

        // Endpoint accepts an empty data object.
        $payload = ['data' => (object) []];

        return $this->request('POST', $url, $payload, []);
    }

    /**
     * @param array<string,mixed>|string|null $body
     * @param array<string,string> $headers
     * @return array<string,mixed>|WP_Error
     */
    private function request(string $method, string $url, $body, array $headers)
    {
        $args = [
            'method'  => $method,
            'timeout' => 20,
            'headers' => array_merge(
                [
                    'Authorization' => $this->authHeader(),
                    'Accept'        => 'application/json',
                ],
                $headers
            ),
        ];

        if ($body !== null) {
            $args['headers']['Content-Type'] = 'application/json';
            $args['body'] = is_string($body) ? $body : wp_json_encode($body);
        }

        $this->logger->debug('PayMongo request {method} {url}', ['method' => $method, 'url' => $url]);

        $response = wp_remote_request($url, $args);
        if (is_wp_error($response)) {
            return $response;
        }

        $code = (int) wp_remote_retrieve_response_code($response);
        $raw  = (string) wp_remote_retrieve_body($response);
        $data = json_decode($raw, true);

        if ($code < 200 || $code >= 300) {
            $this->logger->error('PayMongo API error HTTP {code}', ['code' => $code, 'body' => '[redacted]']);
            return new WP_Error(
                'paymongo_api_error',
                'PayMongo API error (HTTP ' . $code . ').',
                [
                    'http_code' => $code,
                    'body'      => '[redacted]',
                    'body_hash' => hash('sha256', $raw),
                    'parsed'    => is_array($data) ? $data : null,
                ]
            );
        }

        return is_array($data) ? $data : ['raw' => $raw];
    }

    private function authHeader(): string
    {
        // PayMongo uses Basic auth: base64(secret_key + ":")
        return 'Basic ' . base64_encode($this->secretKey . ':');
    }
}
