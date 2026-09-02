<?php

/**
 * YOURLS core route definitions.
 *
 * YOURLS' front-facing URL space is deliberately tiny: everything that is not a real file is a
 * "keyword" (optionally suffixed with "+" or "+all" for the stats page), plus a couple of fixed
 * endpoints (favicon, robots). Rather than a route-per-keyword (impossible), we use one catch-all
 * route whose controller reproduces the exact branching the old yourls-loader.php performed.
 *
 * Keeping this in a RouteCollection means plugins/tests can inspect or extend routing, and the
 * matching itself goes through Symfony's UrlMatcher.
 *
 * @since 1.11
 */

namespace YOURLS\Router;

use Symfony\Component\Routing\Route;
use Symfony\Component\Routing\RouteCollection;

class Routes {

    /**
     * Build the core RouteCollection.
     *
     * @return RouteCollection
     */
    public static function collection(): RouteCollection {
        $routes = new RouteCollection();

        // Fixed endpoints handled before YOURLS bootstrap in the front controller, but declared
        // here too so the collection is a complete description of the URL space.
        $routes->add('favicon', new Route('/favicon.ico', [
            '_controller' => [Dispatcher::class, 'favicon'],
        ]));

        $routes->add('robots', new Route('/robots.txt', [
            '_controller' => [Dispatcher::class, 'robots'],
        ]));

        // Catch-all: any keyword-shaped request. The controller decides go vs. infos vs.
        // bookmarklet vs. not-found, mirroring the legacy loader.
        $catchall = new Route('/{request}', [
            '_controller' => [Dispatcher::class, 'dispatchKeyword'],
            'request'     => '',
        ]);
        // {request} may contain slashes and '+' (bookmarklet URLs, stats suffix).
        $catchall->setRequirement('request', '.*');
        $routes->add('keyword', $catchall);

        return $routes;
    }
}
