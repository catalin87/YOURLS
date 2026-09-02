<?php

/**
 * YOURLS front controller.
 *
 * Every request that is not a real file goes through here (see the rewrite rules in .htaccess or
 * web.config). The request is turned into a Symfony HttpFoundation Request, routed by Symfony
 * Routing, and answered with a Response.
 *
 * Legacy hooks (pre_load_template, load_template_go, ...) still fire at the same points of the
 * lifecycle, and legacy plugins that echo output or call exit() inside a hook keep working: see
 * \YOURLS\Http\Kernel and \YOURLS\Http\LegacyRuntime.
 */

use Symfony\Component\HttpFoundation\Request;
use YOURLS\Http\Kernel;

require_once __DIR__ . '/includes/vendor/autoload.php';

/* Handle inexistent root favicon.ico and robots.txt requests and exit.
 * This is done before YOURLS is loaded: these must be served even when YOURLS is not installed
 * yet or the database is unreachable. */
$early = Kernel::handle_root_request(Request::createFromGlobals());
if ($early !== null) {
    $early->send();
    exit;
}

// Load YOURLS
require_once __DIR__ . '/includes/load-yourls.php';

/* Build the Request only now: booting YOURLS runs yourls_fix_request_uri(), which repairs
 * $_SERVER['REQUEST_URI'] on IIS and strips $_COOKIE out of $_REQUEST. Snapshotting the globals
 * before that would route on an unfixed URI. */
$request = Request::createFromGlobals();

$kernel   = new Kernel();
$response = $kernel->handle($request);

$kernel->send($response, $request);
