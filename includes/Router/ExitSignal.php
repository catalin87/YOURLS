<?php

/**
 * Control-flow exception used to end a request the "Symfony way" instead of calling exit().
 *
 * Legacy YOURLS dispatch ended each branch with require + exit(). In the new Request/Response
 * lifecycle we cannot let a controller call exit() (it would bypass Response sending and the
 * output buffer accounting). Instead, a controller throws an ExitSignal carrying an optional
 * Response (e.g. a redirect) or a status code; the Kernel catches it and produces the final
 * Response, folding in any buffered output.
 *
 * This is intentionally a lightweight signal, NOT an error condition.
 *
 * @since 1.11
 */

namespace YOURLS\Router;

use Symfony\Component\HttpFoundation\Response;

class ExitSignal extends \RuntimeException {

    /**
     * A pre-built response to return, if any.
     *
     * @var Response|null
     */
    protected ?Response $response;

    /**
     * HTTP status code to use when no explicit response is provided.
     *
     * @var int
     */
    protected int $status;

    /**
     * Whether the underlying code already emitted its own status line / headers (legacy
     * templates calling header()/yourls_redirect()). When true, the Kernel must NOT let Symfony
     * re-send a status line; it only flushes the captured body.
     *
     * @var bool
     */
    protected bool $raw = false;

    /**
     * @param Response|null $response Optional ready response (e.g. a RedirectResponse).
     * @param int           $status   Fallback status code when building a Response from buffer.
     */
    public function __construct(?Response $response = null, int $status = 200) {
        parent::__construct('YOURLS request terminated via ExitSignal');
        $this->response = $response;
        $this->status   = $status;
    }

    /**
     * Convenience constructor for "just end here with whatever was echoed".
     *
     * @param int  $status
     * @param bool $raw    True when the caller (e.g. a legacy template) already sent its own
     *                     headers/status and only the buffered body should be flushed.
     * @return self
     */
    public static function end(int $status = 200, bool $raw = false): self {
        $signal = new self(null, $status);
        $signal->raw = $raw;
        return $signal;
    }

    /**
     * @return bool Whether this signal represents an already-headered (raw) response.
     */
    public function isRaw(): bool {
        return $this->raw;
    }

    /**
     * Convenience constructor wrapping an explicit Response.
     *
     * @param Response $response
     * @return self
     */
    public static function withResponse(Response $response): self {
        return new self($response);
    }

    /**
     * Resolve this signal into a Response, folding in any buffered output.
     *
     * @param string $buffered Output captured by the kernel before the signal was thrown.
     * @return Response
     */
    public function toResponse(string $buffered): Response {
        if ($this->response !== null) {
            // For redirects and other explicit responses, prepend any stray echoed output only
            // if the response has no body of its own (redirects normally don't).
            $existing = (string) $this->response->getContent();
            if ($buffered !== '' && $existing === '') {
                $this->response->setContent($buffered);
            } elseif ($buffered !== '') {
                $this->response->setContent($buffered . $existing);
            }
            return $this->response;
        }

        return new Response($buffered, $this->status);
    }
}
