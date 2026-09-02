<?php
/**
 * YOURLS front controller.
 *
 * Historically this file handled the public request with a pile of global variables and inline
 * control flow. It now boots YOURLS and delegates dispatch to a Symfony Routing + HttpFoundation
 * kernel (\YOURLS\Http\Kernel), which preserves the exact legacy hook lifecycle
 * (pre_load_template BEFORE dispatch) while tolerating plugins/templates that echo, redirect or
 * exit() inside hooks. See includes/Http/Kernel.php for the compatibility contract.
 */

use Symfony\Component\HttpFoundation\Response;
use YOURLS\Http\Request;
use YOURLS\Http\Kernel;

// Handle inexistent root favicon requests and exit
if ( '/favicon.ico' == $_SERVER['REQUEST_URI'] ) {
    header( 'Content-Type: image/gif' );
    echo base64_decode( "R0lGODlhEAAQAJECAAAAzFZWzP///wAAACH5BAEAAAIALAAAAAAQABAAAAIplI+py+0PUQAgSGoNQFt0LWTVOE6GuX1H6onTVHaW2tEHnJ1YxPc+UwAAOw==" );
    exit;
}

// Handle inexistent root robots.txt requests and exit
if ( '/robots.txt' == $_SERVER['REQUEST_URI'] ) {
    header( 'Content-Type: text/plain; charset=utf-8' );
    echo "User-agent: *\n";
    echo "Disallow:\n";
    exit;
}

// Load YOURLS
require_once __DIR__ . '/includes/load-yourls.php';

// Build the HttpFoundation request from PHP globals and dispatch through the kernel.
//
// The kernel computes the YOURLS request (yourls_get_request()), fires the `pre_load_template`
// action before any dispatch, matches the request via Symfony Routing, and runs the matching
// handler (go / infos / bookmarklet / not-found). Because legacy hooks and templates commonly
// echo/exit, the kernel captures output and a shutdown guard flushes it — so an exit() inside a
// plugin hook still yields a complete response. If nothing exited, we send the Response here.
$kernel   = new Kernel( Request::createFromGlobals() );
$response = $kernel->handle();

if ( $response instanceof Response ) {
    $response->send();
}
