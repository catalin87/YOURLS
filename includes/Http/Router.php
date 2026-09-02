<?php

declare(strict_types=1);

/**
 * The YOURLS front controller routes.
 *
 * These reproduce, one for one, the behaviour of the regular expression that yourls-loader.php
 * used to run against the request:
 *
 *     preg_match( "@^(.+?)(\+(all)?)?/?$@", $request, $matches );
 *
 * ie "anything" is a keyword, "anything+" is its stats page, and "anything+all" is the aggregate
 * stats page. The keyword pattern is deliberately lazy (".+?") so that a keyword containing a
 * plus sign, such as "a+b+", still resolves to keyword "a+b" and the stats page.
 *
 * @since 1.10.5
 */

namespace YOURLS\Http;

use Symfony\Component\Routing\Route;
use Symfony\Component\Routing\RouteCollection;

class Router {

    /**
     * A request that begins with a scheme is a "Prefix-n-Shorten" bookmarklet call, eg
     * https://sho.rt/http://example.com/some/page
     */
    private const SCHEME_PATTERN = '[a-zA-Z][a-zA-Z0-9+.\-]*://.*';

    /**
     * Build the route collection
     *
     * @since  1.10.5
     * @return RouteCollection
     */
    public static function routes(): RouteCollection {
        $routes = new RouteCollection();

        // Root requests that YOURLS answers itself, before anything else
        $routes->add('favicon', new Route('/favicon.ico', ['_controller' => 'favicon']));
        $routes->add('robots', new Route('/robots.txt', ['_controller' => 'robots']));

        // A full URL: send it to the bookmarklet page in the admin area
        $routes->add('bookmarklet', new Route(
            '/{url}',
            ['_controller' => 'bookmarklet'],
            ['url' => self::SCHEME_PATTERN]
        ));

        // "keyword+all": aggregate stats page. Declared before "keyword+" so it wins.
        $routes->add('infos_all', new Route(
            '/{keyword}+all',
            ['_controller' => 'infos', 'aggregate' => true],
            ['keyword' => '.+?']
        ));

        // "keyword+": stats page
        $routes->add('infos', new Route(
            '/{keyword}+',
            ['_controller' => 'infos', 'aggregate' => false],
            ['keyword' => '.+?']
        ));

        // "keyword": the short URL itself, or a page
        $routes->add('go', new Route(
            '/{keyword}',
            ['_controller' => 'go'],
            ['keyword' => '.+']
        ));

        return $routes;
    }
}
