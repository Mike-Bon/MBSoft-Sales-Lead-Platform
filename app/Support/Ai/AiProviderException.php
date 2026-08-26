<?php

namespace App\Support\Ai;

use RuntimeException;

/**
 * Any failure talking to the LLM provider (auth error, rate limit,
 * timeout, connection failure, malformed response) — deliberately one
 * generic exception type rather than provider-specific ones, so
 * AssistantService has a single, simple thing to catch (STEP 28: the
 * CRM must remain fully functional when the AI provider is unavailable).
 * The message is always safe to log; it is never shown to the end user
 * verbatim (AssistantService maps it to a generic user-facing message).
 */
class AiProviderException extends RuntimeException {}
