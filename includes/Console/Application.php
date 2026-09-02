<?php

/**
 * The YOURLS Symfony Console application.
 *
 * Registers YOURLS' own commands (yourls:install) plus Doctrine's migration commands, all bound to
 * the live YOURLS DBAL connection via MigrationsFactory. Booted by bin/console.
 *
 * @since 1.11
 */

namespace YOURLS\Console;

use Symfony\Component\Console\Application as BaseApplication;
use YOURLS\Database\MigrationsFactory;

class Application extends BaseApplication {

    public function __construct() {
        $version = defined('YOURLS_VERSION') ? YOURLS_VERSION : 'dev';
        parent::__construct('YOURLS Console', $version);

        // YOURLS commands.
        $this->add(new InstallCommand());

        // Doctrine Migrations commands (migrations:migrate, migrations:status, migrations:diff, ...).
        $this->registerDoctrineMigrationCommands();
    }

    /**
     * Register the Doctrine Migrations console commands, wired to the YOURLS connection.
     *
     * These are optional niceties: if the migrations package isn't fully available the install
     * command still works via MigrationsFactory::migrateToLatest(). We guard the registration so a
     * missing class never breaks the whole console.
     *
     * @return void
     */
    protected function registerDoctrineMigrationCommands(): void {
        // Only attempt if YOURLS is configured enough to build a connection.
        if (!defined('YOURLS_DB_NAME')) {
            return;
        }

        try {
            $dependencyFactory = MigrationsFactory::dependencyFactory();
        } catch (\Throwable $e) {
            // No DB / not configured yet — skip Doctrine command registration silently.
            return;
        }

        $commandClasses = [
            \Doctrine\Migrations\Tools\Console\Command\ExecuteCommand::class,
            \Doctrine\Migrations\Tools\Console\Command\MigrateCommand::class,
            \Doctrine\Migrations\Tools\Console\Command\ListCommand::class,
            \Doctrine\Migrations\Tools\Console\Command\StatusCommand::class,
            \Doctrine\Migrations\Tools\Console\Command\UpToDateCommand::class,
            \Doctrine\Migrations\Tools\Console\Command\VersionCommand::class,
            \Doctrine\Migrations\Tools\Console\Command\CurrentCommand::class,
            \Doctrine\Migrations\Tools\Console\Command\LatestCommand::class,
        ];

        foreach ($commandClasses as $class) {
            if (class_exists($class)) {
                // Doctrine migration commands accept the DependencyFactory in their constructor.
                $this->add(new $class($dependencyFactory));
            }
        }
    }
}
