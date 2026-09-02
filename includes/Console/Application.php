<?php

declare(strict_types=1);

/**
 * The YOURLS console application.
 *
 * Bundles the YOURLS commands (yourls:install, ...) with Doctrine's own migration commands
 * (migrations:migrate, migrations:status, migrations:diff, ...), all wired to the YOURLS
 * connection and to the migrations living in includes/Database/Migrations.
 *
 * @since 1.10.5
 */

namespace YOURLS\Console;

use Doctrine\Migrations\Tools\Console\Command\CurrentCommand;
use Doctrine\Migrations\Tools\Console\Command\DiffCommand;
use Doctrine\Migrations\Tools\Console\Command\DumpSchemaCommand;
use Doctrine\Migrations\Tools\Console\Command\ExecuteCommand;
use Doctrine\Migrations\Tools\Console\Command\GenerateCommand;
use Doctrine\Migrations\Tools\Console\Command\LatestCommand;
use Doctrine\Migrations\Tools\Console\Command\ListCommand;
use Doctrine\Migrations\Tools\Console\Command\MigrateCommand;
use Doctrine\Migrations\Tools\Console\Command\RollupCommand;
use Doctrine\Migrations\Tools\Console\Command\StatusCommand;
use Doctrine\Migrations\Tools\Console\Command\SyncMetadataCommand;
use Doctrine\Migrations\Tools\Console\Command\UpToDateCommand;
use Doctrine\Migrations\Tools\Console\Command\VersionCommand;
use Symfony\Component\Console\Application as BaseApplication;
use YOURLS\Database\MigrationFactory;

class Application extends BaseApplication {

    public function __construct() {
        parent::__construct('YOURLS', defined('YOURLS_VERSION') ? YOURLS_VERSION : 'unknown');

        $this->add(new InstallCommand());

        $this->add_migration_commands();
    }

    /**
     * Register Doctrine's migration commands against the YOURLS connection
     *
     * @since  1.10.5
     * @return void
     */
    protected function add_migration_commands(): void {
        $ydb = yourls_get_db('write-console_migrations');

        $factory = MigrationFactory::create($ydb->get_connection());

        foreach ([
            CurrentCommand::class,
            DiffCommand::class,
            DumpSchemaCommand::class,
            ExecuteCommand::class,
            GenerateCommand::class,
            LatestCommand::class,
            ListCommand::class,
            MigrateCommand::class,
            RollupCommand::class,
            StatusCommand::class,
            SyncMetadataCommand::class,
            UpToDateCommand::class,
            VersionCommand::class,
        ] as $command) {
            $this->add(new $command($factory));
        }
    }
}
