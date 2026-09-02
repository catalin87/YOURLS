<?php

/**
 * YOURLS front-controller kernel built on Symfony Routing + HttpFoundation.
 *
 * This is the modern replacement for the procedural dispatch logic that used to live inline in
 * yourls-loader.php (which relied on ad-hoc globals like $keyword / $stats / $matches). It:
 *
 *   1. Builds a Symfony\Component\HttpFoundation\Request from PHP globals.
 *   2. Fires the legacy `pre_load_template` action BEFORE any dispatch, exactly as before, so
 *      plugins hooking that action keep working unchanged.
 *   3. Matches the request against a small RouteCollection and invokes the matched handler.
 *   4. Returns a Symfony\Component\HttpFoundation\Response.
 *
 * CRITICAL LEGACY-COMPATIBILITY CONSTRAINT
 * ----------------------------------------
 * Old YOURLS templates (yourls-go.php, yourls-infos.php) and old plugins routinely `echo`
 * directly and/or call `exit()` / `die()` inside hooks. A naive "buffer everything, return one
 * Response at the very end" model would lose output (and skip our own finalization) the moment
 * a plugin calls exit(). To stay safe:
 *
 *   - The whole dispatch runs inside an output buffer, so direct `echo` from templates/hooks is
 *     captured into the Response body instead of leaking to the client prematurely.
 *   - A shutdown handler (registered by the caller via handleAndSend) guarantees that even if a
 *     hook calls exit()/die(), whatever was buffered so far is flushed to the client and the
 *     request terminates cleanly. Nothing is truncated, and no "headers already sent" fatal is
 *     produced by our own code.
 *   - Handlers that intentionally end the request (redirects, template includes that historically
 *     called exit) throw a small ExitSignal exception instead of exit(), which we translate into a
 *     normal Response. Legacy code that STILL calls exit() directly is caught by the shutdown net.
 *
 * @since 1.11
 */

namespace YOURLS\Router;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Matcher\UrlMatcher;
use Symfony\Component\Routing\RequestContext;
use Symfony\Component\Routing\RouteCollection;
use Symfony\Component\Routing\Exception\ResourceNotFoundException;

class Kernel {

    /**
     * @var RouteCollection
     */
    protected RouteCollection $routes;

    /**
     * Whether an output buffer we opened is currently active.
     *
     * @var bool
     */
    protected bool $buffering = false;

    /**
     * Whether the last handled response is "raw": the underlying legacy code already emitted its
     * own status line/headers, so handleAndSend() must echo the body instead of Response::send().
     *
     * @var bool
     */
    protected bool $lastResponseRaw = false;

    /**
     * @param RouteCollection|null $routes Optional custom routes; defaults to YOURLS core routes.
     */
    public function __construct(?RouteCollection $routes = null) {
        $this->routes = $routes ?? Routes::collection();
    }

    /**
     * Build a Request from PHP superglobals.
     *
     * @return Request
     */
    public function createRequestFromGlobals(): Request {
        return Request::createFromGlobals();
    }

    /**
     * Handle a request and return a Response, without sending it.
     *
     * This performs the legacy `pre_load_template` action then routes. It is wrapped so that
     * legacy `echo` is captured and legacy `exit()` is survivable (see class docblock).
     *
     * @param Request $request
     * @return Response
     */
    public function handle(Request $request): Response {
        // The "request" YOURLS cares about is the keyword relative to YOURLS base. We compute it
        // via the existing, battle-tested yourls_get_request() (handles subdir installs, sanitize,
        // the shunt_get_request filter, etc.) rather than re-implementing it.
        $yourls_request = yourls_get_request();

        // Fire the legacy lifecycle hook BEFORE dispatch. Plugins hooked here may echo or exit;
        // we are already inside the output buffer opened below, and the shutdown net covers exit().
        $this->openBuffer();
        yourls_do_action('pre_load_template', $yourls_request);

        try {
            $response = $this->dispatch($request, $yourls_request);
        } catch (ExitSignal $signal) {
            // A handler asked to end the request "the old way" (e.g. after a redirect or a
            // template include that historically called exit). Turn it into a normal Response.
            $this->lastResponseRaw = $signal->isRaw();
            $response = $signal->toResponse($this->flushBuffer());
            return $response;
        }

        // Prepend anything the handler/templates echoed directly to the response body.
        $buffered = $this->flushBuffer();
        if ($buffered !== '') {
            $response->setContent($buffered . $response->getContent());
        }

        return $response;
    }

    /**
     * Handle the current request from globals and send the response to the client.
     *
     * Registers a shutdown safety net FIRST, so that a legacy exit()/die() inside any hook or
     * template still results in the buffered output being flushed to the browser.
     *
     * @return void
     */
    public function handleAndSend(): void {
        // Safety net: if anything calls exit()/die() before we send, flush what we buffered.
        register_shutdown_function([$this, 'onShutdown']);

        $request  = $this->createRequestFromGlobals();
        $response = $this->handle($request);

        if ($this->lastResponseRaw) {
            // Legacy template already sent its own status line / headers (via header() /
            // yourls_redirect()). Only flush the captured body so we don't clobber them.
            echo $response->getContent();
            return;
        }

        $response->send();
    }

    /**
     * Match the request to a route and invoke its handler.
     *
     * @param Request $request
     * @param string  $yourls_request The keyword-relative request string.
     * @return Response
     */
    protected function dispatch(Request $request, string $yourls_request): Response {
        $context = new RequestContext();
        $context->fromRequest($request);
        $matcher = new UrlMatcher($this->routes, $context);

        // We match against the YOURLS-relative request (the keyword), normalized to a leading
        // slash so Symfony's path matching applies. The catch-all route handles keyword/stats.
        $path = '/' . ltrim($yourls_request, '/');

        try {
            $parameters = $matcher->match($path);
        } catch (ResourceNotFoundException $e) {
            $parameters = ['_controller' => [Dispatcher::class, 'notFound']];
        }

        $controller = $parameters['_controller'];
        unset($parameters['_controller'], $parameters['_route']);

        // Controllers receive (Request $request, string $yourls_request, array $params).
        return call_user_func($controller, $request, $yourls_request, $parameters);
    }

    /**
     * Open an output buffer we control.
     *
     * @return void
     */
    protected function openBuffer(): void {
        if (!$this->buffering) {
            ob_start();
            $this->buffering = true;
        }
    }

    /**
     * Close our output buffer and return its contents.
     *
     * @return string
     */
    protected function flushBuffer(): string {
        if (!$this->buffering) {
            return '';
        }
        $this->buffering = false;
        $content = ob_get_clean();
        return $content === false ? '' : $content;
    }

    /**
     * Shutdown handler: last-resort flush if a legacy exit()/die() bypassed normal return.
     *
     * If our buffer is still open when the script terminates (because a hook called exit()),
     * emit whatever was captured so the client still gets the intended output instead of a
     * blank/truncated page.
     *
     * @return void
     */
    public function onShutdown(): void {
        if (!$this->buffering) {
            return;
        }
        $this->buffering = false;
        $content = ob_get_clean();
        // Emit whatever the templates/hooks captured before the legacy exit()/die(). Any raw
        // header()/yourls_status_header() calls the template made were already sent by PHP, so we
        // only need to flush the body here (whether or not headers are already sent).
        if ($content !== false && $content !== '') {
            echo $content;
        }
    }
}
