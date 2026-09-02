<?php

/**
 * YOURLS front controller kernel
 *
 * Turns an incoming HttpFoundation Request into a Response, using Symfony Routing to decide what
 * the request means (a short URL, a stats page, a bookmarklet, a page, ...).
 *
 * ---------------------------------------------------------------------------------------------
 * Legacy plugin compatibility
 * ---------------------------------------------------------------------------------------------
 * YOURLS plugins are procedural and predate any notion of a Response object. A plugin hooked on
 * 'pre_load_template' (or any other action fired here) is fully entitled to:
 *
 *   - echo output directly, expecting it to reach the browser;
 *   - call exit() or die() to end the request on the spot;
 *   - call yourls_redirect(), which sends a Location header with PHP's header() and returns;
 *   - do all three.
 *
 * None of that fits a "build a Response, then send it" lifecycle, so this kernel bridges the two:
 *
 *   - Everything runs inside an output buffer. Whatever legacy code echoes is captured and
 *     prepended to the Response body instead of being interleaved with it (or being swallowed).
 *   - A shutdown handler flushes that buffer if the request dies mid-flight, so a plugin calling
 *     exit() still delivers its output and its headers, exactly like it did before 1.11.
 *   - If legacy code already sent headers (yourls_redirect(), yourls_status_header(), ...), the
 *     kernel does not try to send its own on top: it hands the captured body over and stops.
 *
 * The upshot: plugins written against the pre-1.11 lifecycle keep working untouched, while core
 * code gets a real Request/Response to work with.
 *
 * @since 1.11
 */

namespace YOURLS\Http;

use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Exception\ResourceNotFoundException;
use Symfony\Component\Routing\Matcher\UrlMatcher;
use Symfony\Component\Routing\RequestContext;

class Kernel {

    /**
     * Is the output buffer we opened still ours to close?
     * @var bool
     */
    protected bool $buffering = false;

    /**
     * Have we already handed a Response over to the client?
     * @var bool
     */
    protected bool $sent = false;

    /**
     * Handle a request and send the resulting response
     *
     * @since  1.11
     * @param  Request $request
     * @return void
     */
    public function run(Request $request): void {
        $this->start_buffering();

        $response = $this->handle($request);

        $this->send($response);
    }

    /**
     * Turn a request into a response
     *
     * @since  1.11
     * @param  Request $request
     * @return Response
     */
    public function handle(Request $request): Response {
        // Get request in YOURLS base (eg in 'http://sho.rt/yourls/abcd' get 'abcd')
        // At this point, $yourls_request is NOT sanitized.
        $yourls_request = yourls_get_request('', $request->getRequestUri());

        /* Legacy hook, fired before anything is dispatched. Plugins hooked here may echo, may
         * exit(), and may redirect: see the class docblock for how that is accommodated.
         *
         * Like the pre-1.11 loader, we do NOT stop here if a plugin merely redirected without
         * exiting: dispatch carries on and the last header() call wins, which is the behaviour
         * plugins were written against.
         */
        yourls_do_action( 'pre_load_template', $yourls_request );

        /* If the hook produced output, hand control back to the legacy model by flushing it now.
         * This makes headers_sent() true from here on, so yourls_redirect() and friends take the
         * same branch they took before 1.11 (a Javascript redirect rather than a Location header).
         * Buffering it any longer would silently change how those functions behave.
         */
        $this->flush_if_output();

        $controller = new Controller();

        try {
            $match = $this->match($yourls_request);
        } catch (ResourceNotFoundException $e) {
            /* Not a valid short URL, not a bookmarklet. The old loader still handed the parsed
             * keyword to 'redirect_keyword_not_found', so pass the request rather than an empty
             * string: plugins hooked there expect to see what was asked for.
             */
            return $controller->not_found($yourls_request, rtrim($yourls_request, '/'));
        }

        $response = match ($match['_controller']) {
            'favicon' => $controller->favicon(),
            'robots'  => $controller->robots(),
            'infos'   => $controller->infos($match['keyword'], $yourls_request),
            default   => $controller->keyword($match['keyword'], $yourls_request),
        };

        return $this->merge_buffer($response);
    }

    /**
     * Match a YOURLS request against the route collection
     *
     * @since  1.11
     * @param  string $yourls_request Request relative to the YOURLS base, eg 'abcd+'
     * @return array                  Route defaults, including '_controller'
     * @throws ResourceNotFoundException
     */
    protected function match(string $yourls_request): array {
        // A bookmarklet request ('https://sho.rt/http://example.com') is not a routable path
        if (yourls_get_protocol($yourls_request)) {
            return ['_controller' => 'keyword', 'keyword' => $yourls_request];
        }

        /* The pre-1.11 loader matched its regex against the request *as received*, so 'a%2Bb' was a
         * keyword containing a literal '%2B', not the stats page for 'a'. UrlMatcher rawurldecodes
         * the path before matching, which would silently merge those two cases.
         *
         * Escaping just the percent signs keeps the two apart: a real '+' reaches the matcher as
         * '+' (and can match the stats route), while a literal '%2B' arrives as '%252B', survives
         * the matcher's decoding as '%2B', and stays an ordinary keyword. Undo it afterwards.
         */
        $path = '/'.str_replace('%', '%25', ltrim($yourls_request, '/'));

        $matcher = new UrlMatcher(Routes::collection(), new RequestContext());
        $match   = $matcher->match($path);

        return $match;
    }

    /**
     * Send a response to the client
     *
     * @since  1.11
     * @param  Response $response
     * @return void
     */
    public function send(Response $response): void {
        if ($this->sent) {
            return;
        }
        $this->sent = true;

        $content = $response->getContent();
        $legacy  = $this->legacy_took_over();

        $this->stop_buffering();

        /* Legacy code (yourls_redirect(), yourls_status_header(), a plugin calling header()) may
         * have already sent or queued the status line and headers. Sending our own on top would
         * override them - a 301 redirect from yourls-go.php would become a 200 - so in that case
         * only the body goes out, and PHP flushes the headers legacy code set.
         */
        if ($legacy) {
            echo $content;

            return;
        }

        $response->send();
    }

    /**
     * Has legacy code taken responsibility for the response headers?
     *
     * Note that headers_sent() alone is not enough to answer this: while we are buffering, header()
     * calls are merely *queued*, so headers_sent() stays false even though yourls_redirect() has
     * already set a Location and a status code. Look at what is queued, not just at what went out.
     *
     * @since  1.11
     * @return bool
     */
    protected function legacy_took_over(): bool {
        if (headers_sent()) {
            return true;
        }

        // A status code other than the default means something called header()/http_response_code()
        if (http_response_code() !== 200) {
            return true;
        }

        foreach (headers_list() as $header) {
            if (stripos($header, 'Location:') === 0) {
                return true;
            }
        }

        return false;
    }

    /**
     * Start capturing output produced by legacy code
     *
     * @since  1.11
     * @return void
     */
    protected function start_buffering(): void {
        if ($this->buffering) {
            return;
        }

        ob_start();
        $this->buffering = true;

        /* If a plugin (or a legacy template) calls exit(), we never get back to send(): PHP just
         * unwinds to shutdown. Flush what was buffered so that output isn't lost.
         */
        register_shutdown_function([$this, 'flush_on_exit']);
    }

    /**
     * Return and close the output buffer we opened
     *
     * @since  1.11
     * @return string  Whatever legacy code echoed, or '' if we weren't buffering
     */
    protected function stop_buffering(): string {
        // flush_pending_output() may have closed our buffer already, so check, don't assume
        if (!$this->buffering || ob_get_level() === 0) {
            $this->buffering = false;

            return '';
        }

        $this->buffering = false;

        return (string)ob_get_clean();
    }

    /**
     * Flush buffered output when the request ends without going through send()
     *
     * Registered as a shutdown function by start_buffering(). This is what makes a plugin calling
     * exit() inside a hook behave exactly like it did before the Request/Response lifecycle.
     *
     * @since  1.11
     * @return void
     */
    public function flush_on_exit(): void {
        if (!$this->buffering || ob_get_level() === 0) {
            $this->buffering = false;

            return;
        }

        $this->buffering = false;
        // Hand the buffered content straight to the client: there is no Response to build anymore
        ob_end_flush();
    }

    /**
     * Flush the buffer if legacy code has produced output, and stop buffering
     *
     * Once a hook has echoed something, the pre-1.11 lifecycle had headers already sent, and core
     * functions branch on exactly that (yourls_redirect() falls back to a Javascript redirect,
     * yourls_status_header() gives up, yourls_die() skips its own <head>). Holding the output in a
     * buffer would keep headers_sent() false and silently change all of it, so let it go.
     *
     * @since  1.11
     * @return void
     */
    protected function flush_if_output(): void {
        if (!$this->buffering || ob_get_length() === 0) {
            return;
        }

        $this->buffering = false;
        self::flush_pending_output();
    }

    /**
     * Send any buffered output to the client right now
     *
     * Called by the controller just before it runs a legacy template, which must see the same
     * "headers already sent" state it saw when the pre-1.11 loader require'd it directly.
     *
     * The instance flag is cleared through flush_if_output() when we own the buffer; this static
     * form is safe to call from anywhere, and simply does nothing if there is nothing pending.
     *
     * @since  1.11
     * @return void
     */
    public static function flush_pending_output(): void {
        // Nothing pending: stay quiet. Calling flush() here would commit the headers and rob
        // whatever runs next of its chance to send a real Location or status line.
        if (ob_get_level() === 0 || ob_get_length() === 0) {
            return;
        }

        while (ob_get_level() > 0 && ob_get_length() > 0) {
            ob_end_flush();
        }

        flush();
    }

    /**
     * Prepend buffered legacy output to a response body
     *
     * Anything echoed by a hook or a legacy template belongs *before* the response body, in the
     * order it was produced.
     *
     * A RedirectResponse carries its own little HTML body, which no client ever displays. Replace
     * it with the buffered output rather than appending to it: when a legacy template has echoed
     * something and then redirected, that output is the whole point.
     *
     * @since  1.11
     * @param  Response $response
     * @return Response
     */
    protected function merge_buffer(Response $response): Response {
        $buffered = $this->stop_buffering();

        if ($buffered === '') {
            return $response;
        }

        if ($response instanceof RedirectResponse) {
            $response->setContent($buffered);
        } else {
            $response->setContent($buffered.$response->getContent());
        }

        return $response;
    }

}
