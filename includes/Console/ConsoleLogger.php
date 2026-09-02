<?php

declare(strict_types=1);

/**
 * Minimal PSR-3 logger that forwards Doctrine Migrations output to the console.
 *
 * @since 1.10.5
 */

namespace YOURLS\Console;

use Psr\Log\AbstractLogger;
use Psr\Log\LogLevel;
use Stringable;
use Symfony\Component\Console\Style\SymfonyStyle;

class ConsoleLogger extends AbstractLogger {

    /**
     * Levels that are only shown when the console is run with -v
     */
    private const VERBOSE_LEVELS = [LogLevel::DEBUG, LogLevel::INFO, LogLevel::NOTICE];

    public function __construct(private SymfonyStyle $io) {}

    /**
     * @param mixed             $level
     * @param string|Stringable $message
     * @param array             $context
     */
    public function log($level, string|Stringable $message, array $context = []): void {
        $message = $this->interpolate((string)$message, $context);

        if (in_array($level, [LogLevel::EMERGENCY, LogLevel::ALERT, LogLevel::CRITICAL, LogLevel::ERROR], true)) {
            $this->io->text('<error>' . $message . '</error>');

            return;
        }

        if ($level === LogLevel::WARNING) {
            $this->io->text('<comment>' . $message . '</comment>');

            return;
        }

        if (in_array($level, self::VERBOSE_LEVELS, true) && !$this->io->isVerbose()) {
            return;
        }

        $this->io->text('  ' . $message);
    }

    /**
     * Replace {placeholders} in the message with their context values
     *
     * @param  string $message
     * @param  array  $context
     * @return string
     */
    private function interpolate(string $message, array $context): string {
        if ($context === []) {
            return $message;
        }

        $replacements = [];
        foreach ($context as $key => $value) {
            if (is_scalar($value) || $value instanceof Stringable || $value === null) {
                $replacements['{' . $key . '}'] = (string)$value;
            }
        }

        return strtr($message, $replacements);
    }
}
