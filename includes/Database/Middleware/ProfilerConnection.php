<?php

/**
 * Connection wrapper that times every statement and reports it to the YOURLS profiler.
 *
 * @since 1.10.5
 */

namespace YOURLS\Database\Middleware;

use Doctrine\DBAL\Driver\Connection;
use Doctrine\DBAL\Driver\Middleware\AbstractConnectionMiddleware;
use Doctrine\DBAL\Driver\Result;
use Doctrine\DBAL\Driver\Statement;
use YOURLS\Database\Profiler;

class ProfilerConnection extends AbstractConnectionMiddleware {

    public function __construct(Connection $connection, protected Profiler $profiler) {
        parent::__construct($connection);
    }

    public function prepare(string $sql): Statement {
        return new ProfilerStatement(parent::prepare($sql), $this->profiler, $sql);
    }

    public function query(string $sql): Result {
        $this->profiler->start('query');
        try {
            return parent::query($sql);
        } finally {
            $this->profiler->finish($sql);
        }
    }

    public function exec(string $sql): int|string {
        $this->profiler->start('exec');
        try {
            return parent::exec($sql);
        } finally {
            $this->profiler->finish($sql);
        }
    }
}
