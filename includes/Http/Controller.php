<?php

/**
 * Controllers for the YOURLS front controller
 *
 * Each method turns one kind of request into a Response. The legacy actions that used to be fired
 * from yourls-loader.php ('load_template_go', 'load_template_infos', 'redirect_keyword_not_found',
 * 'loader_failed', ...) are fired here, at the same points in the lifecycle and with the same
 * arguments, so plugins hooked on them keep working.
 *
 * The 'go' and 'infos' templates are legacy scripts that echo a whole page (and may exit): they are
 * run inside an output buffer and their output becomes the response body.
 *
 * @since 1.11
 */

namespace YOURLS\Http;

use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Response;

class Controller {

    /**
     * A 1x1 transparent GIF, served for /favicon.ico when the install has no real favicon
     */
    private const FAVICON = 'R0lGODlhEAAQAJECAAAAzFZWzP///wAAACH5BAEAAAIALAAAAAAQABAAAAIplI+py+0PUQAgSGoNQFt0LWTVOE6GuX1H6onTVHaW2tEHnJ1YxPc+UwAAOw==';

    /**
     * Handle inexistent root favicon requests
     *
     * @since  1.11
     * @return Response
     */
    public function favicon(): Response {
        return new Response(base64_decode(self::FAVICON), Response::HTTP_OK, [
            'Content-Type' => 'image/gif',
        ]);
    }

    /**
     * Handle inexistent root robots.txt requests
     *
     * @since  1.11
     * @return Response
     */
    public function robots(): Response {
        return new Response("User-agent: *\nDisallow:\n", Response::HTTP_OK, [
            'Content-Type' => 'text/plain; charset=utf-8',
        ]);
    }

    /**
     * Handle a request for a keyword: a short URL, a page, or a bookmarklet URL
     *
     * @since  1.11
     * @param  string $keyword        The requested keyword, unsanitized
     * @param  string $yourls_request The full request relative to the YOURLS base
     * @return Response
     */
    public function keyword(string $keyword, string $yourls_request): Response {
        /* If the request has a scheme (eg scheme://uri), this is "Prefix-n-Shorten": send the URL
         * over to the bookmarklet in the admin area. (Doesn't work on Windows.)
         */
        if ( yourls_get_protocol($keyword) ) {
            return $this->bookmarklet($keyword);
        }

        // The old loader regex ended in "/?$", so 'abcd/' resolved the very same short URL as 'abcd'
        $keyword = rtrim($keyword, '/');

        // An existing short URL, or an existing page
        if ( yourls_keyword_is_taken($keyword) or yourls_is_page($keyword) ) {
            yourls_do_action( 'load_template_go', $keyword );

            return $this->render_template(YOURLS_ABSPATH.'/yourls-go.php', ['keyword' => $keyword]);
        }

        return $this->not_found($yourls_request, $keyword);
    }

    /**
     * Handle a request for a stats page: 'abcd+' or 'abcd+all'
     *
     * @since  1.11
     * @param  string $requested      The matched route part, ie the keyword with its '+' suffix
     * @param  string $yourls_request The full request relative to the YOURLS base
     * @return Response
     */
    public function infos(string $requested, string $yourls_request): Response {
        /* Split 'abcd+all' into the keyword ('abcd') and the aggregation flag ('all'), tolerating a
         * trailing slash exactly like the pre-1.11 loader regex "@^(.+?)(\+(all)?)?/?$@" did.
         */
        preg_match( "@^(.+?)\+(all)?/?$@", $requested, $matches );
        $keyword   = $matches[1] ?? $requested;
        $stats_all = $matches[2] ?? null;

        if ( !yourls_keyword_is_taken($keyword) && !yourls_is_page($keyword) ) {
            return $this->not_found($yourls_request, $keyword);
        }

        $aggregate = (bool)$stats_all && yourls_allow_duplicate_longurls();
        yourls_do_action( 'load_template_infos', $keyword );

        return $this->render_template(YOURLS_ABSPATH.'/yourls-infos.php', [
            'keyword'   => $keyword,
            'aggregate' => $aggregate,
        ]);
    }

    /**
     * Redirect a bookmarklet request to the admin area
     *
     * @since  1.11
     * @param  string $keyword The requested keyword, which here is a full URL
     * @return Response
     */
    public function bookmarklet(string $keyword): Response {
        $url   = yourls_sanitize_url_safe($keyword);
        $parse = yourls_get_protocol_slashes_and_rest( $url, [ 'up', 'us', 'ur' ] );
        yourls_do_action( 'load_template_redirect_admin', $url );
        yourls_do_action( 'pre_redirect_bookmarklet', $url );

        // Redirect to /admin/index.php?up=<url protocol>&us=<url slashes>&ur=<url rest>
        return $this->redirect( yourls_add_query_arg( $parse, yourls_admin_url( 'index.php' ) ), 302 );
    }

    /**
     * Handle a request the loader could not understand
     *
     * Not a valid short URL, not a page, not a bookmarklet.
     *
     * @since  1.11
     * @param  string $yourls_request The full request relative to the YOURLS base
     * @param  string $keyword        The keyword we tried to resolve, if any
     * @return Response
     */
    public function not_found(string $yourls_request, string $keyword): Response {
        yourls_do_action( 'redirect_keyword_not_found', $keyword );
        yourls_do_action( 'loader_failed', $yourls_request );

        return $this->redirect( yourls_get_yourls_site(), 302 );
    }

    /**
     * Build a redirect response, letting plugins filter it like yourls_redirect() would
     *
     * @since  1.11
     * @param  string $location
     * @param  int    $code
     * @return Response
     */
    protected function redirect(string $location, int $code): Response {
        yourls_do_action( 'pre_redirect', $location, $code );
        $location = yourls_apply_filter( 'redirect_location', $location, $code );
        $code     = (int)yourls_apply_filter( 'redirect_code', $code, $location );

        $response = new RedirectResponse($location, $code);

        /* RedirectResponse fills the body with an HTML "Redirecting to ..." page. YOURLS has always
         * sent a bare Location header with no body, and some clients (and the test suite) compare
         * responses byte for byte, so drop it. Kernel::merge_buffer() puts back anything a legacy
         * hook echoed.
         */
        $response->setContent('');

        return $response;
    }

    /**
     * Run a legacy template
     *
     * The template expects its inputs as local variables (eg $keyword), the way it did when
     * yourls-loader.php require'd it into its own scope, so extract them.
     *
     * The template is deliberately NOT wrapped in its own output buffer. These templates interleave
     * output with header() calls and branch on headers_sent(): yourls-go.php echoes whatever the
     * 'load_template_go' hook produced and then redirects, which has to fall back to a Javascript
     * redirect precisely because that output already went out. An extra buffer here would hide it,
     * turn the JS redirect back into a Location header, and change what the visitor gets.
     *
     * Output therefore flows into the Kernel's buffer (or straight to the client, if a hook already
     * forced a flush), and the response we return carries no body of its own.
     *
     * @since  1.11
     * @param  string $template  Absolute path to the template file
     * @param  array  $variables Variables the template expects in its scope
     * @return Response
     */
    protected function render_template(string $template, array $variables): Response {
        /* Hand any output produced so far (typically by the 'load_template_go' /
         * 'load_template_infos' hook that just fired) to the client before the template runs, so
         * headers_sent() is true inside it, exactly as it was when the old loader require'd these
         * files directly. yourls-go.php depends on this to pick its Javascript redirect fallback.
         */
        Kernel::flush_pending_output();

        (static function (string $__template, array $__variables): void {
            extract($__variables, EXTR_SKIP);
            require $__template;
        })($template, $variables);

        return new Response('');
    }

}
