<?php

/**
 * Doctrine Migrations CLI bootstrap for the standalone `vendor/bin/doctrine-migrations` tool.
 *
 * Boots YOURLS (in installing mode so bootstrap does not redirect), then builds a
 * Doctrine\Migrations\DependencyFactory from the live YOURLS DBAL connection and the
 * migrations.php configuration. Prefer `php bin/console migrations:*` which does the same wiring
 * with a nicer UX; this file exists for users who run the vanilla Doctrine CLI.
 *
 * @since 1.11
 */

use Doctrine\Migrations\Configuration\Connection\ExistingConnection;
use Doctrine\Migrations\Configuration\Migration\PhpFile;
use Doctrine\Migrations\DependencyFactory;

if (!defined('YOURLS_INSTALLING')) {
    define('YOURLS_INSTALLING', true);
}
if (!defined('YOURLS_ADMIN')) {
    define('YOURLS_ADMIN', true);
}

require_once __DIR__ . '/includes/load-yourls.php';

$connector = yourls_get_db_connector('write-migrations_cli');
if (!$connector) {
    fwrite(STDERR, "Doctrine DBAL backend is not active. Ensure doctrine/dbal is installed.\n");
    exit(1);
}

$config = new PhpFile(__DIR__ . '/migrations.php');

return DependencyFactory::fromConnection(
    $config,
    new ExistingConnection($connector->getConnection())
);
