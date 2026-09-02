<?php

declare(strict_types=1);

/**
 * Runs legacy YOURLS code (hooks, templates, plugins) inside a Request/Response lifecycle.
 *
 * YOURLS plugins are ordinary PHP: a hook callback is free to `echo` something, to send headers,
 * or to call `exit()` in the middle of a request. None of that fits a "return a Response object"
 * model, so this class bridges the two worlds:
 *
 *  - Anything a legacy callback prints is captured with output buffering and folded into the
 *    Response body, instead of leaking out before the headers are sent.
 *
 *  - If a legacy callback calls `exit()`, PHP unwinds immediately and no Response is ever
 *    returned. A shutdown handler notices that we died inside legacy code and flushes what was
 *    buffered, so the plugin's output (and any header it sent) still reaches the client. This is
 *    what makes an old `exit()`-happy plugin keep working under the new lifecycle.
 *
 *  - Legacy code sends headers with PHP's header() and yourls_status_header(). Those are already
 *    registered with PHP by the time we build the Response, so the Response is created with
 *    whatever status code is current, and Symfony is not allowed to undo them.
 *
 * @since 1.10.5
 */

namespace YOURLS\Http;

use Symfony\Component\HttpFoundation\Response;
use Throwable;

class LegacyRuntime {

    /**
     * Are we currently executing legacy code?
     * @var bool
     */
    private bool $running = false;

    /**
     * Output buffering level recorded when legacy code was entered
     * @var int
     */
    private int $buffer_level = 0;

    /**
     * Has the shutdown handler been registered?
     * @var bool
     */
    private bool $shutdown_registered = false;

    /**
     * Register the shutdown handler that rescues output when legacy code calls exit()
     *
     * @since  1.10.5
     * @return void
     */
    public function register_shutdown(): void {
        if ($this->shutdown_registered) {
            return;
        }

        $this->shutdown_registered = true;

        register_shutdown_function(function (): void {
            if (!$this->running) {
                return;
            }

            /* We are inside legacy code and PHP is shutting down: something called exit() (or a
             * fatal error happened). Flush the buffers we opened so the output that legacy code
             * produced is not swallowed. */
            $this->flush_to($this->buffer_level);
        });
    }

    /**
     * Run a legacy callable, capturing everything it prints.
     *
     * @since  1.10.5
     * @param  callable $callback
     * @return string   Whatever the callback printed
     */
    public function capture(callable $callback): string {
        $this->register_shutdown();

        $was_running        = $this->running;
        $previous_level     = $this->buffer_level;
        $this->running      = true;
        $this->buffer_level = ob_get_level();

        ob_start();

        try {
            $callback();
        } finally {
            $output = $this->close_to($this->buffer_level);

            $this->running      = $was_running;
            $this->buffer_level = $previous_level;
        }

        return $output;
    }

    /**
     * Run a legacy callable and turn the result into a Response.
     *
     * The status code is read back from PHP, so a template that called yourls_status_header(404)
     * or header('Location: ...') still produces a Response carrying that status.
     *
     * @since  1.10.5
     * @param  callable $callback
     * @return Response
     */
    public function respond(callable $callback): Response {
        $body   = $this->capture($callback);
        $status = $this->current_status();

        $response = new Response($body, $status);

        /* Legacy code sent its headers straight to PHP. They are already queued, so tell Symfony
         * not to touch them: sending the Response must not drop a Location or a Content-Type that
         * a plugin set by hand. */
        return self::keep_php_headers($response);
    }

    /**
     * Mark a Response so that sending it leaves the headers PHP already holds alone.
     *
     * @since  1.10.5
     * @param  Response $response
     * @return Response
     */
    public static function keep_php_headers(Response $response): Response {
        $response->headers->set('X-YOURLS-Legacy-Headers', '1');

        return $response;
    }

    /**
     * Is this a Response whose headers were already sent by legacy code?
     *
     * @since  1.10.5
     * @param  Response $response
     * @return bool
     */
    public static function has_php_headers(Response $response): bool {
        return $response->headers->has('X-YOURLS-Legacy-Headers');
    }

    /**
     * Are we currently running legacy code?
     *
     * @since  1.10.5
     * @return bool
     */
    public function is_running(): bool {
        return $this->running;
    }

    /**
     * Close every buffer opened above $level, returning their contents
     *
     * @param  int $level
     * @return string
     */
    private function close_to(int $level): string {
        $output = '';

        while (ob_get_level() > $level) {
            $chunk = ob_get_clean();
            if ($chunk === false) {
                break;
            }
            // Buffers close innermost first, so earlier output belongs in front
            $output = $chunk . $output;
        }

        return $output;
    }

    /**
     * Flush every buffer opened above $level straight to the client
     *
     * @param  int $level
     * @return void
     */
    private function flush_to(int $level): void {
        while (ob_get_level() > $level) {
            try {
                if (!@ob_end_flush()) {
                    break;
                }
            } catch (Throwable $e) {
                break;
            }
        }
    }

    /**
     * The HTTP status code PHP is currently set to send
     *
     * @return int
     */
    private function current_status(): int {
        $status = http_response_code();

        return is_int($status) && $status >= 100 && $status < 600 ? $status : 200;
    }
}
