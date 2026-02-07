<?php
declare(strict_types=1);

namespace Vendor\PaymongoCheckout\Rest;

use Vendor\PaymongoCheckout\Contracts\ServiceInterface;

final class WebhookRouteService implements ServiceInterface
{
    public function register(): void
    {
        add_action('rest_api_init', [$this, 'registerRoutes']);
    }

    public function boot(): void
    {
        // no-op
    }

    public function registerRoutes(): void
    {
        (new WebhookController())->register_routes();
    }
}
