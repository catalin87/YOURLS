<?php

/**
 * Controllers for the YOURLS core routes.
 *
 * Each method reproduces one branch of the historical yourls-loader.php dispatch, but expressed
 * as a controller returning (or signalling) a Symfony Response instead of `require ...; exit;`.
 *
 * IMPORTANT — legacy templates echo and historically exit():
 * The go/infos templates (yourls-go.php, yourls-infos.php) write output with `echo`/`header()`
 * and end with `exit()`/`return`. We run each include inside the Kernel's output buffer, capture
 * whatever they emit, and translate "the request ends here" into an ExitSignal (caught by the
 * Kernel) instead of relying on a real exit(). If a plugin or template STILL calls exit()
 * directly, the Kernel's shutdown net flushes the buffer, so nothing is lost.
 *
 * @since 1.11
 */

namespace YOURLS\Router;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\RedirectResponse;

class Dispatcher {

    /**
     * Emit the built-in favicon (mirrors the legacy loader short-circuit).
     *
     * @param Request $request
     * @param string  $yourls_request
     * @param array   $params
     * @return Response
     */
    public static function favicon(Request $request, string $yourls_request, array $params): Response {
        $gif = base64_decode(
            'R0lGODlhEAAQAJECAAAAzFZWzP///wAAACH5BAEAAAIALAAAAAAQABAAAAIplI+py+0PUQAg'
            . 'SGoNQFt0LWTVOE6GuX1H6onTVHaW2tEHnJ1YxPc+UwAAOw=='
        );
        return new Response($gif, 200, ['Content-Type' => 'image/gif']);
    }

    /**
     * Emit a permissive robots.txt (mirrors the legacy loader short-circuit).
     *
     * @param Request $request
     * @param string  $yourls_request
     * @param array   $params
     * @return Response
     */
    public static function robots(Request $request, string $yourls_request, array $params): Response {
        $body = "User-agent: *\nDisallow:\n";
        return new Response($body, 200, ['Content-Type' => 'text/plain; charset=utf-8']);
    }

    /**
     * The main keyword dispatcher — faithful port of yourls-loader.php's core branching.
     *
     * @param Request $request
     * @param string  $yourls_request The keyword-relative request (already sanitized upstream).
     * @param array   $params
     * @return Response
     */
    public static function dispatchKeyword(Request $request, string $yourls_request, array $params): Response {
        // Parse the request: "anything", "anything+" (stats) or "anything+all" (aggregate stats).
        preg_match("@^(.+?)(\+(all)?)?/?$@", $yourls_request, $matches);
        $keyword   = isset($matches[1]) ? $matches[1] : null;
        $stats     = isset($matches[2]) ? $matches[2] : null;
        $stats_all = isset($matches[3]) ? $matches[3] : null;

        // Bookmarklet: request carrying a scheme (scheme://uri) -> "Prefix-n-Shorten".
        if ($keyword !== null && yourls_get_protocol($keyword)) {
            $url   = yourls_sanitize_url_safe($keyword);
            $parse = yourls_get_protocol_slashes_and_rest($url, ['up', 'us', 'ur']);
            yourls_do_action('load_template_redirect_admin', $url);
            yourls_do_action('pre_redirect_bookmarklet', $url);

            $location = yourls_add_query_arg($parse, yourls_admin_url('index.php'));
            return self::redirect($location, 302);
        }

        // Existing short URL keyword, stats page, or existing page.
        if ($keyword !== null && (yourls_keyword_is_taken($keyword) || yourls_is_page($keyword))) {

            // Plain short URL or page -> go template.
            if ($keyword && !$stats) {
                yourls_do_action('load_template_go', $keyword);
                return self::loadTemplate(YOURLS_ABSPATH . '/yourls-go.php', ['keyword' => $keyword]);
            }

            // Stats page -> infos template.
            if ($keyword && $stats) {
                $aggregate = $stats_all && yourls_allow_duplicate_longurls();
                yourls_do_action('load_template_infos', $keyword);
                return self::loadTemplate(YOURLS_ABSPATH . '/yourls-infos.php', [
                    'keyword'   => $keyword,
                    'stats'     => $stats,
                    'stats_all' => $stats_all,
                    'aggregate' => $aggregate,
                ]);
            }
        }

        // Not a valid shorturl / not a bookmarklet.
        return self::notFound($request, $yourls_request, $params, $keyword);
    }

    /**
     * Not-found handler: fire the legacy actions then redirect to the YOURLS home.
     *
     * @param Request     $request
     * @param string      $yourls_request
     * @param array       $params
     * @param string|null $keyword
     * @return Response
     */
    public static function notFound(
        Request $request,
        string $yourls_request,
        array $params = [],
        ?string $keyword = null
    ): Response {
        yourls_do_action('redirect_keyword_not_found', $keyword);
        yourls_do_action('loader_failed', $yourls_request);
        return self::redirect(YOURLS_SITE, 302);
    }

    /**
     * Include a legacy template file, exposing the given variables to its local scope.
     *
     * The template historically ends with exit()/return and echoes its own markup. We run it and
     * translate its termination into an ExitSignal carrying whatever it echoed, so the Kernel can
     * build the final Response. Any output already written is captured by the Kernel's buffer.
     *
     * @param string $file      Absolute path to the template.
     * @param array  $variables Variables to extract into the template scope (e.g. $keyword).
     * @return Response          (In practice this throws ExitSignal; return type documents intent.)
     */
    protected static function loadTemplate(string $file, array $variables = []): Response {
        // Expose the expected variables ($keyword, $stats, ...) exactly like the old loader did,
        // where they were plain locals in yourls-loader.php visible to the required template.
        extract($variables, EXTR_SKIP);

        // The templates end their own request. Whatever they echo is captured by the Kernel's
        // active output buffer; when the include returns (or would have exit()ed) we signal end.
        require $file;

        // If the template used `return` instead of exit(), we reach here. The template has already
        // sent its own headers/status (echo, header(), yourls_redirect()); we signal a RAW end so
        // the Kernel just flushes the captured body without letting Symfony re-send a status line.
        throw ExitSignal::end(200, true);
    }

    /**
     * Build a RedirectResponse, honoring YOURLS' redirect filters/hooks.
     *
     * We reuse the yourls_* filters (pre_redirect, redirect_location, redirect_code) so plugins
     * that alter redirects keep working, then emit a proper Symfony RedirectResponse.
     *
     * @param string $location
     * @param int    $code
     * @return Response  (throws ExitSignal wrapping the redirect)
     */
    protected static function redirect(string $location, int $code = 302): Response {
        yourls_do_action('pre_redirect', $location, $code);
        $location = yourls_apply_filter('redirect_location', $location, $code);
        $code     = (int) yourls_apply_filter('redirect_code', $code, $location);

        $response = new RedirectResponse($location, $code);
        throw ExitSignal::withResponse($response);
    }
}
