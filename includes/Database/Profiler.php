<?php

/**
 * Custom profiler for YOURLS
 *
 * Historically based on \Aura\Sql\Profiler\Profiler. Since YOURLS runs on Doctrine DBAL, the
 * profiler is standalone: DBAL reports query timings through a middleware (see
 * \YOURLS\Database\Middleware\ProfilerMiddleware) which calls start()/finish() around each
 * statement execution.
 *
 * The public API (isActive/setActive, getLogger/setLogger, setLogLevel, start/finish) is kept
 * identical to what YOURLS and its plugins used to call on the Aura profiler.
 *
 * @since 1.7.10
 */

namespace YOURLS\Database;

use Psr\Log\LoggerInterface;

class Profiler {

    /**
     * Is the profiler logging queries?
     * @var bool
     */
    protected bool $active = false;

    /**
     * @var LoggerInterface
     */
    protected LoggerInterface $logger;

    /**
     * Log level used for queries logged by the profiler itself
     * @var string
     */
    protected string $logLevel = 'debug';

    /**
     * Context of the query being profiled (start time, function name, ...)
     * @var array
     */
    protected array $context = [];

    /**
     * Name of the YOURLS fetch method that triggered the next query, if known.
     *
     * \YOURLS\Database\YDB sets this before running a query so the debug log can report the
     * actual method a caller used (eg 'fetchObjects') rather than the low level driver call.
     *
     * @var string|null
     */
    protected ?string $pending_function = null;

    /**
     * @param LoggerInterface $logger
     */
    public function __construct(LoggerInterface $logger) {
        $this->logger = $logger;
    }

    /**
     * @return LoggerInterface
     */
    public function getLogger(): LoggerInterface {
        return $this->logger;
    }

    /**
     * @param LoggerInterface $logger
     * @return void
     */
    public function setLogger(LoggerInterface $logger): void {
        $this->logger = $logger;
    }

    /**
     * @return bool
     */
    public function isActive(): bool {
        return $this->active;
    }

    /**
     * @param bool $active
     * @return void
     */
    public function setActive(bool $active): void {
        $this->active = $active;
    }

    /**
     * @return string
     */
    public function getLogLevel(): string {
        return $this->logLevel;
    }

    /**
     * Set the log level
     *
     * Note that PHP method names are case insensitive, so this also answers to the setLoglevel()
     * spelling that the Aura profiler used and that YOURLS called it by.
     *
     * @param string $logLevel
     * @return void
     */
    public function setLogLevel(string $logLevel): void {
        $this->logLevel = $logLevel;
    }

    /**
     * Announce which YOURLS fetch method is about to run a query.
     *
     * Called by \YOURLS\Database\YDB::fetch_wrapper(). This replaces the old approach of walking
     * a fixed number of debug_backtrace() frames, which broke whenever the call depth changed.
     *
     * @param string|null $function eg 'fetchObjects', or null to forget the hint
     * @return void
     */
    public function set_pending_function(?string $function): void {
        $this->pending_function = $function;
    }

    /**
     * Start profiling a statement
     *
     * @param string $function The driver level call that triggered the query (eg 'perform')
     * @return void
     */
    public function start(string $function = 'perform'): void {
        if (!$this->active) {
            return;
        }

        $this->context = [
            'function' => $this->pending_function ?? $function,
            'start'    => microtime(true),
        ];
    }

    /**
     * Finishes and logs a profile entry.
     *
     * @param string|null $statement The statement being profiled, if any.
     * @param array       $values    The values bound to the statement, if any.
     * @return void
     */
    public function finish(?string $statement = null, array $values = []): void {
        if (!$this->active || !$this->context) {
            return;
        }

        $this->context['duration']  = microtime(true) - $this->context['start'];
        $this->context['statement'] = $statement;
        $this->context['values']    = (array)$values;

        $this->logger->log($this->logLevel, '', $this->context);

        $this->context = [];
    }
}
