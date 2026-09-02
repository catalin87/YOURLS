<?php

/**
 * The YOURLS front controller route collection
 *
 * These routes are matched against the request *relative to the YOURLS installation* (what
 * yourls_get_request() returns), not against the raw REQUEST_URI: on an install living in
 * https://sho.rt/yourls/, a hit on /yourls/abcd+ is matched as 'abcd+'.
 *
 * @since 1.11
 */

namespace YOURLS\Http;

use Symfony\Component\Routing\Route;
use Symfony\Component\Routing\RouteCollection;

class Routes {

    /**
     * Build the route collection used by the front controller
     *
     * Plugins can add, remove or reorder routes through the 'loader_routes' filter. Order matters:
     * the first matching route wins, and the catch-all 'keyword' route matches nearly everything.
     *
     * @since  1.11
     * @return RouteCollection
     */
    public static function collection(): RouteCollection {
        $routes = new RouteCollection();

        // Inexistent root favicon and robots.txt: answer them ourselves rather than 404/redirect
        $routes->add('favicon', new Route('/favicon.ico', ['_controller' => 'favicon']));
        $routes->add('robots', new Route('/robots.txt', ['_controller' => 'robots']));

        /* Stats pages: 'abcd+' and 'abcd+all', optionally with a trailing slash, as the pre-1.11
         * loader regex ("@^(.+?)(\+(all)?)?/?$@") allowed. A request that merely *contains* a '+'
         * ('abc+def') matches here too and is resolved back to the keyword 'abc', exactly as that
         * regex did.
         */
        $routes->add('infos', new Route(
            '/{keyword}',
            ['_controller' => 'infos'],
            ['keyword' => '.+?\+(all)?/?'],
        ));

        // Anything else is a keyword to redirect, a page, or a bookmarklet URL
        $routes->add('keyword', new Route(
            '/{keyword}',
            ['_controller' => 'keyword'],
            ['keyword' => '.+'],
        ));

        return yourls_apply_filter('loader_routes', $routes);
    }

}
