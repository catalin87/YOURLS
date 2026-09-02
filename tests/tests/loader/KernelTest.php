<?php

use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use YOURLS\Http\Kernel;
use YOURLS\Http\LegacyRuntime;

/**
 * Front controller tests: routing, and the legacy hook lifecycle.
 */
#[\PHPUnit\Framework\Attributes\Group('loader')]
class KernelTest extends PHPUnit\Framework\TestCase {

    /**
     * Hooks added by a test, removed again in tearDown
     * @var array
     */
    protected array $added_hooks = [];

    public function tearDown(): void {
        foreach ($this->added_hooks as [$hook, $callback]) {
            yourls_remove_action($hook, $callback);
            yourls_remove_filter($hook, $callback);
        }
        $this->added_hooks = [];
    }

    /**
     * Register a hook and schedule its removal
     */
    protected function hook(string $hook, callable $callback): void {
        yourls_add_action($hook, $callback);
        $this->added_hooks[] = [$hook, $callback];
    }

    /**
     * Build a Request for a YOURLS path, eg 'ozh' or 'ozh+'
     *
     * The path is placed under the YOURLS base (YOURLS_SITE may live in a subdirectory), so that
     * yourls_get_request() strips it the way it does for a real request.
     */
    protected function request(string $path): Request {
        $base = rtrim((string)parse_url(yourls_get_yourls_site(), PHP_URL_PATH), '/');

        return Request::create($base . '/' . ltrim($path, '/'));
    }

    /**
     * Reach a protected Kernel method
     */
    protected function invoke(Kernel $kernel, string $method, array $args = []) {
        $reflection = new ReflectionMethod($kernel, $method);
        $reflection->setAccessible(true);

        return $reflection->invokeArgs($kernel, $args);
    }

    // ---------------------------------------------------------------- routing

    public static function routing_cases(): Iterator {
        // request              => [controller, keyword, aggregate]
        yield ['abc',           ['go', 'abc', null]];
        yield ['abc+',          ['infos', 'abc', false]];
        yield ['abc+all',       ['infos', 'abc', true]];
        yield ['a+b',           ['go', 'a+b', null]];
        yield ['a+b+',          ['infos', 'a+b', false]];
        yield ['a+b+all',       ['infos', 'a+b', true]];
        yield ['abc/',          ['go', 'abc', null]];
        yield ['abc+/',         ['infos', 'abc', false]];
        yield ['http://ex.com/', ['bookmarklet', null, null]];
        yield ['https://a.b/c',  ['bookmarklet', null, null]];
    }

    /**
     * The routes must reproduce the old regex: "@^(.+?)(\+(all)?)?/?$@"
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('routing_cases')]
    public function test_routing($request, $expected) {
        [$controller, $keyword, $aggregate] = $expected;

        $kernel = new Kernel();
        $match  = $this->invoke($kernel, 'match', [$request, $this->request($request)]);

        $this->assertIsArray($match, "No route matched '$request'");
        $this->assertSame($controller, $match['_controller'], "Wrong controller for '$request'");

        if ($keyword !== null) {
            $this->assertSame($keyword, $match['keyword'], "Wrong keyword for '$request'");
        }
        if ($aggregate !== null) {
            $this->assertSame($aggregate, $match['aggregate'], "Wrong aggregate flag for '$request'");
        }
    }

    /**
     * The keyword regex must behave exactly like the legacy one on arbitrary input
     */
    public function test_routing_matches_legacy_regex() {
        $kernel = new Kernel();

        foreach (['abc', 'abc+', 'abc+all', 'a+b', 'a+b+', 'a+b+all', 'ozh', '+', '++', 'a+all+', 'x'] as $request) {
            preg_match("@^(.+?)(\+(all)?)?/?$@", $request, $legacy);
            $legacy_keyword = $legacy[1] ?? null;
            $legacy_stats   = isset($legacy[2]) && $legacy[2] !== '';

            $match = $this->invoke($kernel, 'match', [$request, $this->request($request)]);
            $this->assertIsArray($match, "No route matched '$request'");

            $this->assertSame($legacy_keyword, $match['keyword'], "Keyword mismatch for '$request'");
            $this->assertSame(
                $legacy_stats,
                $match['_controller'] === 'infos',
                "Stats-page detection mismatch for '$request'"
            );
        }
    }

    // ----------------------------------------------------- favicon & robots

    public function test_favicon_is_served_without_yourls() {
        $response = Kernel::handle_root_request(Request::create('/favicon.ico'));

        $this->assertInstanceOf(Response::class, $response);
        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('image/gif', $response->headers->get('Content-Type'));
        $this->assertStringStartsWith("GIF89a", $response->getContent());
    }

    public function test_robots_is_served_without_yourls() {
        $response = Kernel::handle_root_request(Request::create('/robots.txt'));

        $this->assertInstanceOf(Response::class, $response);
        $this->assertSame(200, $response->getStatusCode());
        $this->assertStringContainsString('User-agent: *', $response->getContent());
    }

    public function test_other_requests_are_not_root_requests() {
        $this->assertNull(Kernel::handle_root_request(Request::create('/ozh')));
    }

    // ------------------------------------------------------- the hook lifecycle

    /**
     * pre_load_template must fire, before dispatch, with the raw request
     */
    public function test_pre_load_template_fires_with_request() {
        $seen = [];
        $this->hook('pre_load_template', function ($request) use (&$seen) {
            // yourls_do_action() hands the callback an array of arguments
            $seen[] = is_array($request) ? $request[0] : $request;
        });

        (new Kernel())->handle($this->request('some-unknown-keyword'));

        $this->assertSame(['some-unknown-keyword'], $seen);
    }

    /**
     * pre_load_template must fire BEFORE the "not found" hooks: order matters
     */
    public function test_hook_order() {
        $order = [];
        foreach (['pre_load_template', 'redirect_keyword_not_found', 'loader_failed'] as $hook) {
            $this->hook($hook, function () use (&$order, $hook) {
                $order[] = $hook;
            });
        }

        (new Kernel())->handle($this->request('nope-not-here'));

        $this->assertSame(['pre_load_template', 'redirect_keyword_not_found', 'loader_failed'], $order);
    }

    /**
     * THE critical constraint: a legacy plugin that echoes inside a hook must not break the
     * Request/Response lifecycle. Its output has to survive into the response body.
     */
    public function test_echo_in_hook_is_captured_into_response() {
        $this->hook('pre_load_template', function () {
            echo 'LEGACY PLUGIN OUTPUT';
        });

        $response = (new Kernel())->handle($this->request('nope-not-here'));

        $this->assertInstanceOf(Response::class, $response);
        $this->assertStringContainsString('LEGACY PLUGIN OUTPUT', $response->getContent());
    }

    /**
     * Echoed output must not be silently swallowed by a redirect either: the redirect is
     * downgraded to an HTML page that still redirects the visitor.
     */
    public function test_echo_in_hook_survives_a_redirect() {
        $this->hook('pre_load_template', function () {
            echo 'BANNER';
        });

        $response = (new Kernel())->handle($this->request('nope-not-here'));

        $this->assertStringContainsString('BANNER', $response->getContent());
        $this->assertStringContainsString('http-equiv="refresh"', $response->getContent());
    }

    /**
     * With no plugin printing anything, a request that matches nothing is a plain redirect home
     */
    public function test_unknown_keyword_redirects_home() {
        $response = (new Kernel())->handle($this->request('nope-not-here'));

        $this->assertInstanceOf(RedirectResponse::class, $response);
        $this->assertSame(302, $response->getStatusCode());
        $this->assertSame(YOURLS_SITE, $response->getTargetUrl());
    }

    /**
     * A plugin may filter the redirect target and status, as yourls_redirect() allows
     */
    public function test_redirect_can_be_filtered() {
        $location = function () { return 'https://example.com/elsewhere'; };
        $code     = function () { return 307; };

        yourls_add_filter('redirect_location', $location);
        yourls_add_filter('redirect_code', $code);

        try {
            $response = (new Kernel())->handle($this->request('nope-not-here'));

            $this->assertSame('https://example.com/elsewhere', $response->getTargetUrl());
            $this->assertSame(307, $response->getStatusCode());
        } finally {
            yourls_remove_filter('redirect_location', $location);
            yourls_remove_filter('redirect_code', $code);
        }
    }

    /**
     * A bookmarklet request ("Prefix-n-Shorten") goes to the admin page, and fires its hooks
     */
    public function test_bookmarklet_redirects_to_admin() {
        $seen = [];
        foreach (['load_template_redirect_admin', 'pre_redirect_bookmarklet'] as $hook) {
            $this->hook($hook, function ($url) use (&$seen, $hook) {
                $seen[$hook] = $url;
            });
        }

        $response = (new Kernel())->handle($this->request('http://example.com/page'));

        $this->assertInstanceOf(RedirectResponse::class, $response);
        $this->assertSame(302, $response->getStatusCode());
        $this->assertStringContainsString('admin/index.php', $response->getTargetUrl());
        $this->assertStringContainsString('up=', $response->getTargetUrl());

        $this->assertArrayHasKey('load_template_redirect_admin', $seen);
        $this->assertArrayHasKey('pre_redirect_bookmarklet', $seen);
    }

    // ------------------------------------------------------------ LegacyRuntime

    public function test_legacy_runtime_captures_output() {
        $runtime = new LegacyRuntime();

        $output = $runtime->capture(function () {
            echo 'hello ';
            echo 'world';
        });

        $this->assertSame('hello world', $output);
    }

    /**
     * Nested buffers opened by legacy code must all be collected, in the right order
     */
    public function test_legacy_runtime_collects_nested_buffers() {
        $runtime = new LegacyRuntime();

        $output = $runtime->capture(function () {
            echo 'a';
            ob_start();
            echo 'b';
            // deliberately left open, as sloppy legacy code would
        });

        $this->assertSame('ab', $output);
    }

    /**
     * Capturing must not leak buffers: the level is the same before and after, even on error
     */
    public function test_legacy_runtime_restores_buffer_level_on_exception() {
        $runtime = new LegacyRuntime();
        $before  = ob_get_level();

        try {
            $runtime->capture(function () {
                echo 'partial';
                throw new RuntimeException('plugin blew up');
            });
            $this->fail('Exception should have propagated');
        } catch (RuntimeException $e) {
            $this->assertSame('plugin blew up', $e->getMessage());
        }

        $this->assertSame($before, ob_get_level(), 'Output buffer leaked');
    }

    public function test_legacy_runtime_is_not_running_outside_capture() {
        $runtime = new LegacyRuntime();
        $this->assertFalse($runtime->is_running());

        $runtime->capture(function () use ($runtime) {
            $this->assertTrue($runtime->is_running());
        });

        $this->assertFalse($runtime->is_running());
    }

    /**
     * A response produced by legacy code is flagged so send() won't re-send PHP's headers
     */
    public function test_legacy_responses_keep_php_headers() {
        $runtime  = new LegacyRuntime();
        $response = $runtime->respond(function () {
            echo 'body';
        });

        $this->assertTrue(LegacyRuntime::has_php_headers($response));
        $this->assertSame('body', $response->getContent());
    }
}
