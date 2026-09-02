<?php

/**
 * YOURLS front-controller kernel — Symfony Routing + HttpFoundation.
 *
 * This replaces the procedural, global-variable driven dispatch that used to live inline in
 * yourls-loader.php. It gives YOURLS a modern Request/Response lifecycle while preserving 100% of
 * the legacy plugin hook contract, which is the whole point of the refactor.
 *
 * ------------------------------------------------------------------------------------------------
 * CRITICAL COMPATIBILITY CONTRACT
 * ------------------------------------------------------------------------------------------------
 * Legacy YOURLS plugins and templates freely use echo, header() and exit()/die() *inside* hooks
 * such as `pre_load_template`, `load_template_go`, `load_template_infos`, etc. A naive Symfony
 * kernel that expects every handler to *return* a Response would break the instant a plugin echoes
 * output or calls exit().
 *
 * To stay compatible, the kernel:
 *   1. Fires the legacy action hooks at exactly the same points, in the same order, as the old
 *      loader (pre_load_template BEFORE any dispatch).
 *   2. Runs the whole dispatch inside an output buffer, so anything a hook or template echoes is
 *      captured into the Response body instead of being lost or double-sent.
 *   3. Treats exit()/die() from within a hook or template as an intentional, valid way to end the
 *      request: a shutdown handler flushes the captured buffer, so "the plugin took over and
 *      exited" still results in a correct, complete response to the client.
 *   4. Never assumes a handler returns a Response — a handler may return a Symfony Response, a
 *      string, or nothing at all (having echoed / redirected / exited itself).
 *
 * @since 1.11
 */

namespace YOURLS\Http;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Matcher\UrlMatcher;
use Symfony\Component\Routing\RequestContext;
use Symfony\Component\Routing\Exception\ResourceNotFoundException;

class Kernel {

    /**
     * The current Symfony request.
     *
     * @var Request
     */
    protected Request $request;

    /**
     * The YOURLS "request" string (keyword/path relative to YOURLS base), as produced by
     * yourls_get_request(). NOT sanitized further here — matches legacy behavior.
     *
     * @var string
     */
    protected string $yourls_request = '';

    /**
     * Whether a shutdown flush guard has been registered.
     *
     * @var bool
     */
    protected bool $shutdown_registered = false;

    /**
     * The ob_get_level() value AFTER we opened our own capture buffer. Used so we only ever touch
     * the buffer we started, never any pre-existing buffer YOURLS/PHP may have opened.
     *
     * @var int
     */
    protected int $capture_level = 0;

    /**
     * @param Request|null $request Optional pre-built request (mostly for tests). Defaults to
     *                              Symfony's Request::createFromGlobals().
     */
    public function __construct(?Request $request = null) {
        $this->request = $request ?? Request::createFromGlobals();
    }

    /**
     * @return Request
     */
    public function getRequest(): Request {
        return $this->request;
    }

    /**
     * Handle the current request end-to-end and return a Response.
     *
     * The caller (yourls-loader.php) then calls send() on the returned Response. Because legacy
     * templates commonly exit() themselves, handle() may never actually return — in that case the
     * registered shutdown guard has already flushed the captured output. Either path yields a
     * correct response to the client.
     *
     * @return Response
     */
    public function handle(): Response {
        // Compute the YOURLS request string exactly as before (uses $_SERVER internally, filtered).
        $this->yourls_request = (string) yourls_get_request();

        // Start capturing everything hooks/templates echo, so we can build a Response body from it
        // AND so an exit() inside a hook still results in a flushed, complete response.
        $this->start_output_capture();

        // --- Legacy lifecycle hook: fire BEFORE any dispatch. Plugins may echo/redirect/exit here.
        yourls_do_action( 'pre_load_template', $this->yourls_request );

        // Match the request to a route and dispatch. Any Response/string the handler returns is
        // merged with captured output; if the handler exited, the shutdown guard handles it.
        try {
            $response = $this->dispatch( $this->yourls_request );
        } catch (\Throwable $e) {
            // Never let a dispatch error swallow already-produced output; surface a 500 with body.
            $response = $this->finish_response(
                new Response('', Response::HTTP_INTERNAL_SERVER_ERROR)
            );
            yourls_do_action( 'loader_exception', $e );
            return $response;
        }

        return $this->finish_response( $response );
    }

    /**
     * Route + dispatch the YOURLS request.
     *
     * Mirrors the old yourls-loader.php control flow, but expressed as Symfony routes. Each matched
     * route maps to a handler method. Handlers may echo, redirect, exit, or return a Response.
     *
     * @param string $request The YOURLS request string.
     * @return Response|string|null
     */
    protected function dispatch( string $request ) {
        $routes  = RouteCollectionFactory::create();
        $context = new RequestContext();
        $context->fromRequest( $this->request );
        $matcher = new UrlMatcher( $routes, $context );

        // We route on the *classified* YOURLS request rather than the raw PATH_INFO, because the
        // "keyword vs stats vs bookmarklet vs not-found" decision depends on YOURLS state (DB
        // lookups, page files), not just the URL shape. RouteCollectionFactory encodes the shapes;
        // the classification below picks the internal path we ask the matcher to resolve.
        $internal_path = $this->classify( $request );

        try {
            $parameters = $matcher->match( $internal_path );
        } catch ( ResourceNotFoundException $e ) {
            return $this->handle_not_found( $request );
        }

        $handler = $parameters['_controller'];
        return $this->{$handler}( $request );
    }

    /**
     * Classify the YOURLS request into an internal routing path.
     *
     * This reproduces the exact branch logic of the legacy loader:
     *   - a request with a protocol (scheme://...) => bookmarklet "prefix-n-shorten"
     *   - an existing keyword or page, without stats marker => go (redirect/page)
     *   - an existing keyword or page, with stats marker ('+', '+all') => infos (stats page)
     *   - anything else => not found
     *
     * @param string $request
     * @return string Internal path understood by the route collection.
     */
    protected function classify( string $request ): string {
        // Split like the old loader: "anything", "anything+", "anything+all".
        preg_match( "@^(.+?)(\+(all)?)?/?$@", $request, $matches );
        $keyword   = $matches[1] ?? null;
        $stats     = $matches[2] ?? null;

        // Stash the parsed pieces for the handlers (avoids re-parsing).
        $this->request->attributes->set( 'yourls_keyword', $keyword );
        $this->request->attributes->set( 'yourls_stats', $stats );
        $this->request->attributes->set( 'yourls_stats_all', $matches[3] ?? null );

        // Bookmarklet: request itself is a full URL.
        if ( yourls_get_protocol( $keyword ) ) {
            return '/-bookmarklet';
        }

        // Existing short URL keyword or page.
        if ( yourls_keyword_is_taken( $keyword ) || yourls_is_page( $keyword ) ) {
            if ( $keyword && !$stats ) {
                return '/-go';
            }
            if ( $keyword && $stats ) {
                return '/-infos';
            }
        }

        return '/-not-found';
    }

    /**
     * Handler: bookmarklet "Prefix-n-Shorten" — redirect to the admin bookmarklet URL.
     *
     * @param string $request
     * @return Response|null
     */
    protected function handleBookmarklet( string $request ) {
        $keyword = $this->request->attributes->get( 'yourls_keyword' );

        $url   = yourls_sanitize_url_safe( $keyword );
        $parse = yourls_get_protocol_slashes_and_rest( $url, [ 'up', 'us', 'ur' ] );
        yourls_do_action( 'load_template_redirect_admin', $url );
        yourls_do_action( 'pre_redirect_bookmarklet', $url );

        // Redirect to /admin/index.php?up=<url protocol>&us=<url slashes>&ur=<url rest>
        $location = yourls_add_query_arg( $parse, yourls_admin_url( 'index.php' ) );
        return $this->redirect( $location, 302 );
    }

    /**
     * Handler: existing short URL keyword or page => go template (redirect or render page).
     *
     * @param string $request
     * @return Response|null
     */
    protected function handleGo( string $request ) {
        $keyword = $this->request->attributes->get( 'yourls_keyword' );

        yourls_do_action( 'load_template_go', $keyword );

        // The go template (and pages) may echo, redirect, or exit. We expose $keyword to it exactly
        // as the old `require yourls-go.php` did (it read $keyword from the enclosing scope).
        $this->require_template( YOURLS_ABSPATH . '/yourls-go.php', [ 'keyword' => $keyword ] );

        // If the template returned normally (a YOURLS page uses `return`, not exit), the captured
        // output is the body.
        return null;
    }

    /**
     * Handler: stat page => infos template.
     *
     * @param string $request
     * @return Response|null
     */
    protected function handleInfos( string $request ) {
        $keyword   = $this->request->attributes->get( 'yourls_keyword' );
        $stats_all = $this->request->attributes->get( 'yourls_stats_all' );

        $aggregate = $stats_all && yourls_allow_duplicate_longurls();
        yourls_do_action( 'load_template_infos', $keyword );

        $this->require_template( YOURLS_ABSPATH . '/yourls-infos.php', [
            'keyword'   => $keyword,
            'aggregate' => $aggregate,
        ] );

        return null;
    }

    /**
     * Handler: request the loader could not understand => fire hooks and redirect to YOURLS_SITE.
     *
     * @param string $request
     * @return Response|null
     */
    protected function handle_not_found( string $request ) {
        $keyword = $this->request->attributes->get( 'yourls_keyword' );

        yourls_do_action( 'redirect_keyword_not_found', $keyword );
        yourls_do_action( 'loader_failed', $request );
        return $this->redirect( YOURLS_SITE, 302 );
    }

    /**
     * Issue a redirect while remaining compatible with the legacy yourls_redirect() semantics.
     *
     * yourls_redirect() fires pre_redirect / redirect_location / redirect_code filters and sends the
     * Location header itself (it does NOT exit — the caller used to). We call it so all those hooks
     * still fire, then return a HttpFoundation Response carrying the same status/Location so the
     * Request/Response lifecycle is well-formed even though the header was already emitted.
     *
     * @param string $location
     * @param int    $code
     * @return Response
     */
    protected function redirect( string $location, int $code = 302 ): Response {
        // Let YOURLS run its redirect hooks and emit the header (matches historical behavior).
        yourls_redirect( $location, $code );

        $response = new Response('', $code);
        $response->headers->set( 'Location', $location );
        return $response;
    }

    /**
     * Include a legacy template in an isolated scope, exposing the given variables (mirroring the
     * way the old loader `require`d yourls-go.php / yourls-infos.php with $keyword in scope).
     *
     * @param string $file
     * @param array  $vars
     * @return void
     */
    protected function require_template( string $file, array $vars = [] ): void {
        if ( !is_file( $file ) ) {
            return;
        }
        (static function () use ( $file, $vars ) {
            // Extract the template variables into the include scope.
            extract( $vars, EXTR_SKIP );
            require $file;
        })();
    }

    /**
     * Begin capturing output and register a shutdown guard so exit()/die() inside a hook or
     * template still flushes a complete response.
     *
     * @return void
     */
    protected function start_output_capture(): void {
        ob_start();
        $this->capture_level = ob_get_level();

        if ( !$this->shutdown_registered ) {
            $this->shutdown_registered = true;
            $capture_level =& $this->capture_level;
            register_shutdown_function( function () use ( &$capture_level ) {
                // If a hook/template exited while our buffer was still open, flush it (and anything
                // nested above it) so the client receives the complete response the plugin intended.
                // finish_response() sets $capture_level to 0 once it has cleanly drained the buffer,
                // in which case there is nothing left for us to do here.
                while ( $capture_level > 0 && ob_get_level() >= $capture_level ) {
                    @ob_end_flush();
                    $capture_level--;
                }
            } );
        }
    }

    /**
     * Assemble the final Response from any handler return value plus captured output.
     *
     * @param Response|string|null $result
     * @return Response
     */
    protected function finish_response( $result ): Response {
        // Drain only the capture buffer we opened (never a pre-existing YOURLS/PHP buffer).
        $captured = '';
        if ( $this->capture_level > 0 && ob_get_level() >= $this->capture_level ) {
            $captured = (string) ob_get_clean();
        }
        // Mark our buffer as cleanly drained so the shutdown guard leaves it alone.
        $this->capture_level = 0;

        if ( $result instanceof Response ) {
            // If the handler didn't set a body but produced echo output, use the captured output.
            if ( $result->getContent() === '' && $captured !== '' ) {
                $result->setContent( $captured );
            }
            return $result;
        }

        if ( is_string( $result ) && $result !== '' ) {
            return new Response( $captured . $result );
        }

        // Handler echoed (or exited) — the captured output is the body.
        return new Response( $captured );
    }
}
