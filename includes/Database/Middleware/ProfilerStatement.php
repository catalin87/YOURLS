<?php

/**
 * Statement wrapper that times execution and records bound values for the YOURLS profiler.
 *
 * Bound values are captured as they are bound so the debug log can pretty-print the query with
 * its parameters, the way the Aura SQL profiler used to.
 *
 * @since 1.10.5
 */

namespace YOURLS\Database\Middleware;

use Doctrine\DBAL\Driver\Middleware\AbstractStatementMiddleware;
use Doctrine\DBAL\Driver\Result;
use Doctrine\DBAL\Driver\Statement;
use Doctrine\DBAL\ParameterType;
use YOURLS\Database\Profiler;

class ProfilerStatement extends AbstractStatementMiddleware {

    /**
     * Values bound to this statement, keyed by placeholder name (or 1-based position)
     * @var array
     */
    protected array $values = [];

    public function __construct(
        Statement $statement,
        protected Profiler $profiler,
        protected string $sql,
    ) {
        parent::__construct($statement);
    }

    public function bindValue(int|string $param, mixed $value, ParameterType $type): void {
        $this->values[$param] = $value;

        parent::bindValue($param, $value, $type);
    }

    public function execute(): Result {
        $this->profiler->start('perform');
        try {
            return parent::execute();
        } finally {
            $this->profiler->finish($this->sql, $this->values);
        }
    }
}
