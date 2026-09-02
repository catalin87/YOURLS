<?php

/**
 * Driver wrapper that returns profiling connections.
 *
 * @since 1.10.5
 */

namespace YOURLS\Database\Middleware;

use Doctrine\DBAL\Driver;
use Doctrine\DBAL\Driver\Middleware\AbstractDriverMiddleware;
use SensitiveParameter;
use YOURLS\Database\Profiler;

class ProfilerDriver extends AbstractDriverMiddleware {

    public function __construct(Driver $driver, protected Profiler $profiler) {
        parent::__construct($driver);
    }

    public function connect(
        #[SensitiveParameter]
        array $params,
    ): Driver\Connection {
        return new ProfilerConnection(parent::connect($params), $this->profiler);
    }
}
