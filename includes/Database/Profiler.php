<?php

/**
 * Custom profiler for YOURLS.
 *
 * Historically this class extended \Aura\Sql\Profiler\Profiler. Since YOURLS now runs on
 * Doctrine DBAL (which has no equivalent profiler object), this is a standalone, dependency-free
 * implementation that preserves the exact public surface the rest of YOURLS relies on:
 *
 *   - getLogger()                : returns the PSR-3 \YOURLS\Database\Logger
 *   - setActive(bool) / isActive(): toggle query/debug logging (used by yourls_debug_mode())
 *   - setLogLevel(string) / getLogLevel()
 *   - start() / finish($statement, $values) : bracket a query so it is timed and logged
 *
 * @since 1.7.10
 * @see includes/functions-debug.php
 * @see includes/Database/Logger.php
 */

namespace YOURLS\Database;

use Psr\Log\LoggerInterface;

class Profiler {

    /**
     * @var LoggerInterface
     */
    protected LoggerInterface $logger;

    /**
     * Whether profiling/logging is currently active.
     *
     * @var bool
     */
    protected bool $active = false;

    /**
     * The log level used for query messages (kept as "query" for backward compatibility with
     * \YOURLS\Database\Logger, which formats "query" messages specially).
     *
     * @var string
     */
    protected string $logLevel = 'query';

    /**
     * In-flight profiling context (start time), set by start(), consumed by finish().
     *
     * @var array
     */
    protected array $context = [];

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
     * @param bool $active
     * @return void
     */
    public function setActive(bool $active): void {
        $this->active = $active;
    }

    /**
     * @return bool
     */
    public function isActive(): bool {
        return $this->active;
    }

    /**
     * @param string $logLevel
     * @return void
     */
    public function setLogLevel(string $logLevel): void {
        $this->logLevel = $logLevel;
    }

    /**
     * @return string
     */
    public function getLogLevel(): string {
        return $this->logLevel;
    }

    /**
     * Start timing a query.
     *
     * @return void
     */
    public function start(): void {
        if (!$this->active) {
            return;
        }
        $this->context = ['start' => microtime(true)];
    }

    /**
     * Finish and log a query profile entry.
     *
     * Mirrors the old override: it does not throw, does not collect an unused backtrace, and keeps
     * the array of bound 'values' un-flattened so \YOURLS\Database\Logger can pretty-print them.
     *
     * @param string|null $statement The SQL statement being profiled, if any.
     * @param array       $values    The values bound to the statement, if any.
     * @param string|null $function  Optional. The fetch method name (fetchAll, fetchOne, perform...)
     *                               so the Logger can label the query without walking a backtrace.
     * @return void
     */
    public function finish(?string $statement = null, array $values = [], ?string $function = null): void {
        if (!$this->active) {
            return;
        }

        $context = $this->context;
        $context['duration'] = microtime(true) - ($context['start'] ?? microtime(true));
        $context['statement'] = $statement;
        $context['values'] = (array) $values;
        if ($function !== null) {
            // Logger labels a query as "perform" unless the name starts with "fetch".
            $context['function'] = str_starts_with($function, 'fetch') ? $function : 'perform';
        }

        $this->logger->log($this->logLevel, (string) $statement, $context);

        $this->context = [];
    }
}
