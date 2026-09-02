<?php

/**
 * Builds the Symfony RouteCollection for the YOURLS front controller.
 *
 * YOURLS' public dispatch has only a handful of outcomes (see the legacy yourls-loader.php):
 * bookmarklet redirect, short-URL "go", stats "infos", and not-found. The actual decision between
 * them depends on YOURLS runtime state (DB lookups for keywords, filesystem lookups for pages), so
 * the Kernel classifies the request into one of these internal paths and the matcher resolves the
 * path to a controller method name.
 *
 * Keeping these as real Symfony routes (rather than a bare switch) means plugins and future code
 * can inspect / extend the collection via the `yourls_routes` filter, and admin sub-apps can be
 * mounted on the same collection later.
 *
 * @since 1.11
 */

namespace YOURLS\Http;

use Symfony\Component\Routing\RouteCollection;
use Symfony\Component\Routing\Route;

class RouteCollectionFactory {

    /**
     * @return RouteCollection
     */
    public static function create(): RouteCollection {
        $routes = new RouteCollection();

        // Internal dispatch paths produced by Kernel::classify(). The '_controller' value is the
        // Kernel method that handles that outcome.
        $routes->add( 'yourls_bookmarklet', new Route( '/-bookmarklet', [ '_controller' => 'handleBookmarklet' ] ) );
        $routes->add( 'yourls_go',          new Route( '/-go',          [ '_controller' => 'handleGo' ] ) );
        $routes->add( 'yourls_infos',       new Route( '/-infos',       [ '_controller' => 'handleInfos' ] ) );
        $routes->add( 'yourls_not_found',   new Route( '/-not-found',   [ '_controller' => 'handle_not_found' ] ) );

        // Let plugins add or reorder routes (eg to mount custom public endpoints).
        return yourls_apply_filter( 'yourls_routes', $routes );
    }
}
