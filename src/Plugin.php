<?php
declare(strict_types=1);

namespace Vendor\PaymongoCheckout;

use Vendor\PaymongoCheckout\Contracts\ServiceInterface;
use Vendor\PaymongoCheckout\Support\Container;
use Vendor\PaymongoCheckout\Support\PluginContext;
use Vendor\PaymongoCheckout\Infrastructure\Persistence\SettingsRepository;
use Vendor\PaymongoCheckout\Infrastructure\Logging\NullLogger;
use Vendor\PaymongoCheckout\Infrastructure\Logging\WooCommerceLogger;
use Vendor\PaymongoCheckout\Infrastructure\WooCommerce\Compatibility;
use Vendor\PaymongoCheckout\Admin\AdminAssetsService;
use Vendor\PaymongoCheckout\Admin\PluginLinksService;
use Vendor\PaymongoCheckout\Infrastructure\WooCommerce\GatewayRegistrarService;
use Vendor\PaymongoCheckout\Rest\WebhookRouteService;
use Vendor\PaymongoCheckout\Infrastructure\Cron\ActionSchedulerService;
use Vendor\PaymongoCheckout\Blocks\BlocksService;
use Vendor\PaymongoCheckout\Application\CancelReturnService;

final class Plugin
{
    private Container $container;

    /** @var array<int, class-string<ServiceInterface>> */
    private array $services = [
        PluginLinksService::class,
        GatewayRegistrarService::class,
        WebhookRouteService::class,
        ActionSchedulerService::class,
        BlocksService::class,
        AdminAssetsService::class,
        CancelReturnService::class,
    ];

    public function __construct(?Container $container = null)
    {
        $this->container = $container ?? new Container();
        PluginContext::setContainer($this->container);
        $this->registerCoreBindings();
    }

    public function register(): void
    {
        if (!$this->isWooCommerceActive()) {
            add_action('admin_notices', [$this, 'renderWooCommerceMissingNotice']);
            return;
        }

        add_action('init', [$this, 'loadTextDomain']);

        // Feature compatibility declarations must run before WooCommerce init.
        add_action('before_woocommerce_init', function (): void {
            $this->container->get(Compatibility::class)->declare();
        }, 5);

        foreach ($this->services as $serviceId) {
            $service = $this->container->get($serviceId);
            if ($service instanceof ServiceInterface) {
                $service->register();
            }
        }

        add_action('plugins_loaded', function (): void {
            foreach ($this->services as $serviceId) {
                $service = $this->container->get($serviceId);
                if ($service instanceof ServiceInterface) {
                    $service->boot();
                }
            }
        }, 20);
    }

    public function loadTextDomain(): void
    {
        load_plugin_textdomain(
            WC_PAYMONGO_CHECKOUT_TEXT_DOMAIN,
            false,
            dirname(WC_PAYMONGO_CHECKOUT_PLUGIN_BASENAME) . '/languages'
        );
    }

    public function renderWooCommerceMissingNotice(): void
    {
        if (!current_user_can('activate_plugins')) {
            return;
        }
        echo '<div class="notice notice-warning"><p>'
            . esc_html__('WooCommerce PayMongo Checkout requires WooCommerce to be installed and active.', 'wc-paymongo-checkout')
            . '</p></div>';
    }

    private function isWooCommerceActive(): bool
    {
        return class_exists('WooCommerce');
    }

    private function registerCoreBindings(): void
    {
        $this->container->set(SettingsRepository::class, static function (): SettingsRepository {
            return new SettingsRepository();
        });

        $this->container->set(Compatibility::class, static function (): Compatibility {
            return new Compatibility();
        });

        $this->container->set(NullLogger::class, static function (): NullLogger {
            return new NullLogger();
        });

        $this->container->set(WooCommerceLogger::class, function (Container $c): WooCommerceLogger {
            $settings = $c->get(SettingsRepository::class);
            return new WooCommerceLogger('wc-paymongo-checkout', static function () use ($settings): bool {
                return $settings->getBoolYesNo('debug', false);
            });
        });
    }
}
