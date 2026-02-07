<?php
declare(strict_types=1);

namespace Vendor\PaymongoCheckout\Infrastructure\Logging;

use Psr\Log\NullLogger as PsrNullLogger;

final class NullLogger extends PsrNullLogger implements LoggerInterface
{
}
