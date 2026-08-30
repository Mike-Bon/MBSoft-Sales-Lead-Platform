<?php

namespace App\Support\MarketIntelligence;

use RuntimeException;

/**
 * The single failure type every SearchProvider raises — unavailable,
 * timeout, rate-limited, malformed response, not configured. Callers
 * (ProspectDiscoveryService) translate this into a safe, useful message
 * and never let it break the assistant conversation.
 */
class SearchProviderException extends RuntimeException {}
