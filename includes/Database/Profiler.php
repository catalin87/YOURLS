<?php

/**
 * Query profiler for YOURLS
 *
 * Used to be based on \Aura\Sql\Profiler\Profiler, which YOURLS dropped in 1.11 when moving to
 * Doctrine DBAL. The public API (isActive/setActive/getLogger/...) is kept identical so plugins
 * poking at yourls_get_db()->getProfiler() keep working.
 *
 * @since 1.7.10
 */

namespace YOURLS\Database;

use Psr\Log\LoggerInterface;

class Profiler {

    /**
     * @var LoggerInterface
     */
    protected $logger;

    /**
     * Is the profiler collecting queries?
     * @var bool
     */
    protected bool $active = false;

    /**
     * Log level used for the messages this profiler emits
     * @var string
     */
    protected string $logLevel = 'query';

    /**
     * Start time of the profile entry being recorded, as returned by microtime(true)
     * @var float|null
     */
    protected ?float $start = null;

    /**
     * @since 1.7.10
     * @param LoggerInterface $logger
     */
    public function __construct(LoggerInterface $logger) {
        $this->logger = $logger;
    }

    /**
     * @since  1.7.10
     * @return LoggerInterface
     */
    public function getLogger(): LoggerInterface {
        return $this->logger;
    }

    /**
     * @since  1.7.10
     * @return bool
     */
    public function isActive(): bool {
        return $this->active;
    }

    /**
     * @since  1.7.10
     * @param  bool $active
     * @return void
     */
    public function setActive(bool $active): void {
        $this->active = $active;
    }

    /**
     * @since  1.7.10
     * @return string
     */
    public function getLogLevel(): string {
        return $this->logLevel;
    }

    /**
     * Set the log level
     *
     * Note that PHP method names are case insensitive, so this also answers to the setLoglevel()
     * spelling used by the Aura SQL profiler this class replaced (and by YOURLS core before 1.11).
     *
     * @since  1.7.10
     * @param  string $logLevel
     * @return void
     */
    public function setLogLevel(string $logLevel): void {
        $this->logLevel = $logLevel;
    }

    /**
     * Start recording a profile entry
     *
     * @since  1.11
     * @return void
     */
    public function start(): void {
        if (!$this->active) {
            return;
        }

        $this->start = microtime(true);
    }

    /**
     * Finish and log a profile entry
     *
     * Unlike the Aura profiler this used to extend, we don't collect a backtrace that would remain
     * unused, and we don't flatten the array of bound values into a string.
     *
     * @since  1.7.10
     * @param  string|null $statement The statement being profiled, if any
     * @param  array       $values    The values bound to the statement, if any
     * @param  string      $function  The YOURLS DB method that ran the query, eg 'fetchAll'
     * @return void
     */
    public function finish(?string $statement = null, array $values = [], string $function = 'perform'): void {
        if (!$this->active) {
            return;
        }

        $context = [
            'function'  => $function,
            'duration'  => microtime(true) - ($this->start ?? microtime(true)),
            'statement' => $statement,
            'values'    => $values,
        ];

        $this->logger->log($this->logLevel, '', $context);

        $this->start = null;
    }

}
