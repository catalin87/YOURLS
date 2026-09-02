<?php

/**
 * Doctrine DBAL middleware that feeds the YOURLS profiler.
 *
 * Doctrine has no built-in SQL logger any more (the SQLLogger interface was removed in DBAL 4),
 * so query timing and logging is done by wrapping the driver: every statement that goes through
 * the connection is timed and handed to \YOURLS\Database\Profiler, which in turn logs it via
 * \YOURLS\Database\Logger.
 *
 * This is what keeps yourls_get_num_queries() and yourls_get_debug_log() working.
 *
 * @since 1.10.5
 */

namespace YOURLS\Database\Middleware;

use Doctrine\DBAL\Driver;
use Doctrine\DBAL\Driver\Middleware;
use YOURLS\Database\Profiler;

class ProfilerMiddleware implements Middleware {

    public function __construct(protected Profiler $profiler) {}

    public function wrap(Driver $driver): Driver {
        return new ProfilerDriver($driver, $this->profiler);
    }
}
