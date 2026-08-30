<?php

namespace App\Support\MarketIntelligence;

use RuntimeException;

/**
 * Raised by OutboundUrlGuard when a URL is rejected as unsafe to fetch
 * — wrong scheme/port, a private/loopback/link-local/CGNAT address, a
 * cloud-metadata endpoint, the application's own database or web host,
 * or a hostname that resolves to any of those.
 */
class UnsafeUrlException extends RuntimeException {}
