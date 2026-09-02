<?php

/**
 * YOURLS front controller
 *
 * Every request that isn't a real file lands here (see the .htaccess / web.config the installer
 * writes). Since 1.11 the request is modelled with Symfony's HttpFoundation and dispatched with
 * Symfony Routing, instead of being pattern-matched inline against global variables.
 *
 * The lifecycle is deliberately forgiving towards legacy plugins: hooks such as 'pre_load_template'
 * are fired at the same point they always were, and plugins that echo output or call exit() from a
 * hook keep working unchanged. See \YOURLS\Http\Kernel for how that is guaranteed.
 */

// Load YOURLS
require_once __DIR__ . '/includes/load-yourls.php';

$request = Symfony\Component\HttpFoundation\Request::createFromGlobals();

(new YOURLS\Http\Kernel())->run($request);
