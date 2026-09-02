<?php

/**
 * The YOURLS console application factory.
 *
 * Builds a Symfony\Component\Console\Application, registers the YOURLS commands (install), and —
 * when the Doctrine Migrations component is available — registers the standard migration commands
 * (migrate, status, diff, ...) wired to the YOURLS DBAL connection.
 *
 * @since 1.11
 */

namespace YOURLS\Console;

use Symfony\Component\Console\Application as SymfonyApplication;
use YOURLS\Database\Schema;

class Application {

    /**
     * @return SymfonyApplication
     */
    public static function create(): SymfonyApplication {
        $app = new SymfonyApplication('YOURLS Console', defined('YOURLS_VERSION') ? YOURLS_VERSION : 'dev');

        // YOURLS commands.
        $app->add(new InstallCommand());

        // Doctrine Migrations commands, if installed and if we can build a connection.
        self::registerMigrationCommands($app);

        return $app;
    }

    /**
     * Register Doctrine Migrations commands against the YOURLS DBAL connection.
     *
     * Safe no-op when doctrine/migrations is not installed or the DB isn't reachable yet
     * (e.g. before config is present). This lets `bin/console yourls:install` always work while
     * additionally exposing `bin/console migrations:migrate` etc. when possible.
     *
     * @param SymfonyApplication $app
     * @return void
     */
    protected static function registerMigrationCommands(SymfonyApplication $app): void {
        if (!class_exists(\Doctrine\Migrations\DependencyFactory::class)) {
            return;
        }

        // We need YOURLS bootstrapped (constants + DB) to build the connection. Bootstrap in
        // installing mode so this doesn't redirect. Wrapped in try/catch: if config is missing,
        // we simply skip registering migration commands.
        try {
            if (!defined('YOURLS_INSTALLING')) {
                define('YOURLS_INSTALLING', true);
            }
            if (!defined('YOURLS_ADMIN')) {
                define('YOURLS_ADMIN', true);
            }
            if (!defined('YOURLS_ABSPATH')) {
                require_once dirname(__DIR__, 2) . '/includes/load-yourls.php';
            }

            $connector = yourls_get_db_connector('write-migrations_cli');
            if (!$connector) {
                return;
            }

            $config = new \Doctrine\Migrations\Configuration\Migration\ConfigurationArray([
                'migrations_paths' => [
                    'YOURLS\\Database\\Migrations' => dirname(__DIR__) . '/Database/Migrations',
                ],
                'table_storage' => [
                    'table_name' => Schema::tableName('migrations'),
                ],
                'all_or_nothing'          => false,
                'transactional'           => false,
                'check_database_platform' => false,
            ]);

            $connectionLoader = new \Doctrine\Migrations\Configuration\Connection\ExistingConnection(
                $connector->getConnection()
            );
            $dependencyFactory = \Doctrine\Migrations\DependencyFactory::fromConnection($config, $connectionLoader);

            $app->addCommands([
                new \Doctrine\Migrations\Tools\Console\Command\MigrateCommand($dependencyFactory),
                new \Doctrine\Migrations\Tools\Console\Command\StatusCommand($dependencyFactory),
                new \Doctrine\Migrations\Tools\Console\Command\ListCommand($dependencyFactory),
                new \Doctrine\Migrations\Tools\Console\Command\UpToDateCommand($dependencyFactory),
                new \Doctrine\Migrations\Tools\Console\Command\ExecuteCommand($dependencyFactory),
                new \Doctrine\Migrations\Tools\Console\Command\DiffCommand($dependencyFactory),
                new \Doctrine\Migrations\Tools\Console\Command\GenerateCommand($dependencyFactory),
            ]);
        } catch (\Throwable $e) {
            // Config/DB not ready — expose only yourls:install for now.
        }
    }
}
