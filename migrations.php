<?php

/**
 * Doctrine Migrations configuration for YOURLS.
 *
 * Consumed by the standalone `vendor/bin/doctrine-migrations` CLI (see cli-config.php) and by
 * YOURLS' own `bin/console` migration commands. Migrations live under includes/Database/Migrations
 * in the YOURLS\Database\Migrations namespace. The metadata table name honors YOURLS_DB_PREFIX.
 *
 * @since 1.11
 */

$prefix = defined('YOURLS_DB_PREFIX') ? (string) YOURLS_DB_PREFIX : 'yourls_';

return [
    'table_storage' => [
        'table_name'                 => $prefix . 'migrations',
        'version_column_name'        => 'version',
        'version_column_length'      => 191,
        'executed_at_column_name'    => 'executed_at',
        'execution_time_column_name' => 'execution_time',
    ],
    'migrations_paths' => [
        'YOURLS\\Database\\Migrations' => __DIR__ . '/includes/Database/Migrations',
    ],
    'all_or_nothing'          => false,
    'transactional'           => false,
    'check_database_platform' => false,
    'organize_migrations'     => 'none',
];
