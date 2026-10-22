<?php

declare(strict_types=1);

namespace App\Robinhood\Mcp;

use RuntimeException;

/** Local capacity denial. Transport must not expose this after an HTTP attempt. */
final class BrokerCreditExhausted extends RuntimeException {}
