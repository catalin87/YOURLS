<?php

declare(strict_types=1);

/**
 * The YOURLS front controller.
 *
 * Turns a Symfony Request into a Symfony Response, replacing the global-variable soup that
 * yourls-loader.php used to be, while keeping every legacy hook firing at exactly the same point
 * in the lifecycle, with the same arguments, in the same order:
 *
 *   pre_load_template            before anything is dispatched
 *   load_template_redirect_admin \ bookmarklet ("Prefix-n-Shorten") requests
 *   pre_redirect_bookmarklet     /
 *   load_template_go             a short URL or a page
 *   load_template_infos          a stats page
 *   redirect_keyword_not_found   \ nothing matched
 *   loader_failed                /
 *
 * Because those hooks run arbitrary plugin code, everything they print is captured and folded
 * into the Response, and a plugin that calls exit() still gets its output flushed. See
 * \YOURLS\Http\LegacyRuntime.
 *
 * @since 1.10.5
 */

namespace YOURLS\Http;

use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Exception\MethodNotAllowedException;
use Symfony\Component\Routing\Exception\ResourceNotFoundException;
use Symfony\Component\Routing\Matcher\UrlMatcher;
use Symfony\Component\Routing\RequestContext;

class Kernel {

    /**
     * A 1x1 transparent GIF, served for /favicon.ico so the browser stops asking
     */
    private const FAVICON = 'R0lGODlhEAAQAJECAAAAzFZWzP///wAAACH5BAEAAAIALAAAAAAQABAAAAIplI+py+0PUQAgSGoNQFt0LWTVOE6GuX1H6onTVHaW2tEHnJ1YxPc+UwAAOw==';

    /**
     * @var LegacyRuntime
     */
    protected LegacyRuntime $legacy;

    public function __construct(?LegacyRuntime $legacy = null) {
        $this->legacy = $legacy ?? new LegacyRuntime();
    }

    /**
     * Handle a request and return the response
     *
     * @since  1.10.5
     * @param  Request $request
     * @return Response
     */
    public function handle(Request $request): Response {
        /* The request, relative to the YOURLS base. This is NOT sanitized yet, exactly as it was
         * in the old loader: plugins hooked on 'pre_load_template' expect the raw value. */
        $yourls_request = yourls_get_request('', $request->getRequestUri());

        /* Favicon and robots.txt are answered before YOURLS looks for a keyword. The front
         * controller normally catches these before YOURLS is even loaded; this is here so that
         * handle() is correct on its own, whoever calls it. */
        $early = self::handle_root_request($request);
        if ($early instanceof Response) {
            return $early;
        }

        /* Fire the legacy pre-dispatch hook. A plugin may print something here, or redirect and
         * exit; both are handled. Anything printed is kept and prepended to the final response,
         * so a plugin echoing a banner does not lose its output nor corrupt the headers. */
        $preamble = $this->legacy->capture(function () use ($yourls_request): void {
            yourls_do_action('pre_load_template', $yourls_request);
        });

        $response = $this->dispatch($request, $yourls_request);

        return $this->prepend($response, $preamble);
    }

    /**
     * Answer /favicon.ico and /robots.txt, which YOURLS handles itself.
     *
     * Static, and free of any YOURLS dependency, so the front controller can answer these before
     * YOURLS is bootstrapped - they must work even when YOURLS is not installed or the DB is down.
     *
     * @since  1.10.5
     * @param  Request $request
     * @return Response|null  null if this isn't one of those requests
     */
    public static function handle_root_request(Request $request): ?Response {
        $uri = $request->getRequestUri();

        if ($uri === '/favicon.ico') {
            return new Response(
                base64_decode(self::FAVICON),
                Response::HTTP_OK,
                ['Content-Type' => 'image/gif']
            );
        }

        if ($uri === '/robots.txt') {
            return new Response(
                "User-agent: *\nDisallow:\n",
                Response::HTTP_OK,
                ['Content-Type' => 'text/plain; charset=utf-8']
            );
        }

        return null;
    }

    /**
     * Match the request against the routes and run the matching handler
     *
     * @since  1.10.5
     * @param  Request $request
     * @param  string  $yourls_request  The request relative to the YOURLS base
     * @return Response
     */
    protected function dispatch(Request $request, string $yourls_request): Response {
        $match = $this->match($yourls_request, $request);

        if ($match === null) {
            return $this->not_found($yourls_request, null);
        }

        return match ($match['_controller']) {
            'bookmarklet' => $this->bookmarklet($match['url']),
            'infos'       => $this->infos($match['keyword'], (bool)$match['aggregate'], $yourls_request),
            'go'          => $this->go($match['keyword'], $yourls_request),
            default       => $this->not_found($yourls_request, null),
        };
    }

    /**
     * Match a YOURLS request against the route collection
     *
     * @since  1.10.5
     * @param  string  $yourls_request
     * @param  Request $request
     * @return array|null  The matched route parameters, or null
     */
    protected function match(string $yourls_request, Request $request): ?array {
        /* The routes are written against a path, so build one from the YOURLS request. The
         * trailing slash the old regex tolerated ("abc/" == "abc") is trimmed here. */
        $path = '/' . ltrim($yourls_request, '/');
        if ($path !== '/') {
            $path = rtrim($path, '/');
        }

        $context = new RequestContext();
        $context->fromRequest($request);
        $context->setPathInfo($path);

        $matcher = new UrlMatcher(Router::routes(), $context);

        try {
            return $matcher->match($path);
        } catch (ResourceNotFoundException | MethodNotAllowedException $e) {
            return null;
        }
    }

    /**
     * A request that is a full URL: hand it to the bookmarklet page in the admin area
     *
     * @since  1.10.5
     * @param  string $keyword  The raw request, which is a URL
     * @return Response
     */
    protected function bookmarklet(string $keyword): Response {
        $url   = yourls_sanitize_url_safe($keyword);
        $parse = yourls_get_protocol_slashes_and_rest($url, ['up', 'us', 'ur']);

        $this->legacy->capture(function () use ($url): void {
            yourls_do_action('load_template_redirect_admin', $url);
            yourls_do_action('pre_redirect_bookmarklet', $url);
        });

        // Redirect to /admin/index.php?up=<url protocol>&us=<url slashes>&ur=<url rest>
        return $this->redirect(
            yourls_add_query_arg($parse, yourls_admin_url('index.php')),
            Response::HTTP_FOUND
        );
    }

    /**
     * A short URL, or a page
     *
     * @since  1.10.5
     * @param  string $keyword
     * @param  string $yourls_request
     * @return Response
     */
    protected function go(string $keyword, string $yourls_request): Response {
        if (!$this->keyword_is_known($keyword)) {
            return $this->not_found($yourls_request, $keyword);
        }

        return $this->legacy->respond(function () use ($keyword): void {
            yourls_do_action('load_template_go', $keyword);

            $this->require_template(YOURLS_ABSPATH . '/yourls-go.php', ['keyword' => $keyword]);
        });
    }

    /**
     * A stats page ("keyword+" or "keyword+all")
     *
     * @since  1.10.5
     * @param  string $keyword
     * @param  bool   $aggregate  True for the "+all" variant
     * @param  string $yourls_request
     * @return Response
     */
    protected function infos(string $keyword, bool $aggregate, string $yourls_request): Response {
        if (!$this->keyword_is_known($keyword)) {
            return $this->not_found($yourls_request, $keyword);
        }

        $aggregate = $aggregate && yourls_allow_duplicate_longurls();

        return $this->legacy->respond(function () use ($keyword, $aggregate): void {
            yourls_do_action('load_template_infos', $keyword);

            $this->require_template(
                YOURLS_ABSPATH . '/yourls-infos.php',
                ['keyword' => $keyword, 'aggregate' => $aggregate]
            );
        });
    }

    /**
     * Include a legacy template with the variables it expects.
     *
     * yourls-go.php and yourls-infos.php were written to be require'd from the global scope of
     * yourls-loader.php, so they read plain variables ($keyword, $aggregate) and expect the
     * globals YOURLS sets up ($ydb and friends) to be visible. Both are recreated here: the
     * template's variables are extracted into the local scope, and the YOURLS globals are
     * imported so the template sees exactly what it used to.
     *
     * @since  1.10.5
     * @param  string $template  Absolute path of the file to include
     * @param  array  $variables Variables the template expects, as name => value
     * @return void
     */
    protected function require_template(string $template, array $variables = []): void {
        // Publish the variables globally too: the templates and their hooks may reach for them
        foreach ($variables as $name => $value) {
            $GLOBALS[$name] = $value;
        }

        (static function (string $__template, array $__variables): void {
            // The globals a legacy template expects to find in scope
            global $ydb, $yourls_filters, $yourls_actions, $yourls_locale, $yourls_l10n,
                   $yourls_locale_formats, $yourls_allowedentitynames, $yourls_allowedprotocols,
                   $yourls_reserved_URL, $yourls_user_passwords;

            extract($__variables, EXTR_SKIP);

            require $__template;
        })($template, $variables);
    }

    /**
     * Is this keyword something YOURLS can serve: an existing short URL, or a page?
     *
     * @since  1.10.5
     * @param  string $keyword
     * @return bool
     */
    protected function keyword_is_known(string $keyword): bool {
        return yourls_keyword_is_taken($keyword) || yourls_is_page($keyword);
    }

    /**
     * Nothing matched: fire the legacy "not found" hooks and send the visitor home
     *
     * @since  1.10.5
     * @param  string      $yourls_request
     * @param  string|null $keyword
     * @return Response
     */
    protected function not_found(string $yourls_request, ?string $keyword): Response {
        $this->legacy->capture(function () use ($yourls_request, $keyword): void {
            yourls_do_action('redirect_keyword_not_found', $keyword);
            yourls_do_action('loader_failed', $yourls_request);
        });

        return $this->redirect(YOURLS_SITE, Response::HTTP_FOUND);
    }

    /**
     * Build a redirect, letting plugins filter the location and the status code first.
     *
     * This mirrors yourls_redirect(), which fires 'pre_redirect' and applies the
     * 'redirect_location' and 'redirect_code' filters.
     *
     * @since  1.10.5
     * @param  string $location
     * @param  int    $code
     * @return Response
     */
    protected function redirect(string $location, int $code = Response::HTTP_MOVED_PERMANENTLY): Response {
        $printed = $this->legacy->capture(function () use (&$location, &$code): void {
            yourls_do_action('pre_redirect', $location, $code);
            $location = yourls_apply_filter('redirect_location', $location, $code);
            $code     = (int)yourls_apply_filter('redirect_code', $code, $location);
        });

        $response = new RedirectResponse($location, $code);

        return $this->prepend($response, $printed);
    }

    /**
     * Put whatever legacy code printed in front of a response body.
     *
     * A redirect has no meaningful body, so output produced by a plugin would be lost; rather
     * than drop it, the redirect is downgraded to an HTML page carrying a meta refresh, which is
     * exactly what yourls_redirect() does through yourls_redirect_javascript() when headers have
     * already been sent.
     *
     * @since  1.10.5
     * @param  Response $response
     * @param  string   $output
     * @return Response
     */
    protected function prepend(Response $response, string $output): Response {
        if ($output === '') {
            return $response;
        }

        if ($response instanceof RedirectResponse) {
            $location = $response->getTargetUrl();

            $html = $output
                . sprintf(
                    '<meta http-equiv="refresh" content="0;url=%s" />',
                    yourls_esc_attr($location)
                )
                . sprintf(
                    '<script type="text/javascript">window.location="%s";</script>',
                    yourls_esc_js($location)
                );

            return new Response($html, Response::HTTP_OK, $response->headers->all());
        }

        $response->setContent($output . (string)$response->getContent());

        return $response;
    }

    /**
     * Send a response to the client.
     *
     * When the response came out of legacy code, PHP already holds the headers that code sent, so
     * only the body is echoed: re-sending them through Symfony would either duplicate them or
     * trigger a "headers already sent" warning.
     *
     * @since  1.10.5
     * @param  Response $response
     * @param  Request  $request
     * @return void
     */
    public function send(Response $response, Request $request): void {
        if (LegacyRuntime::has_php_headers($response) || headers_sent()) {
            echo $response->getContent();

            return;
        }

        $response->prepare($request);
        $response->send();
    }
}
