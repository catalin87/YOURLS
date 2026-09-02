<?php

/**
 * YOURLS HTTP Request.
 *
 * A thin extension of Symfony's HttpFoundation Request so YOURLS code can type-hint and construct
 * requests through a stable YOURLS namespace, and so we have a natural home for any YOURLS-specific
 * request helpers we add later (eg reading the YOURLS "request keyword" off the request).
 *
 * @since 1.11
 */

namespace YOURLS\Http;

use Symfony\Component\HttpFoundation\Request as SymfonyRequest;

class Request extends SymfonyRequest {

    /**
     * Build a Request from PHP superglobals, exactly like Symfony's factory.
     *
     * @return static
     */
    public static function fromGlobals(): static {
        return static::createFromGlobals();
    }
}
