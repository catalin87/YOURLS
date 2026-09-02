<?php

/**
 * `yourls:install` console command
 *
 * @since 1.11
 */

namespace YOURLS\Console;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use YOURLS\Database\Installer;

#[AsCommand(
    name: 'yourls:install',
    description: 'Install YOURLS: create the database structure and seed it',
)]
class InstallCommand extends Command {

    protected function configure(): void {
        $this
            ->addOption('skip-htaccess', null, InputOption::VALUE_NONE, 'Do not create or update the .htaccess / web.config file')
            ->addOption('force', null, InputOption::VALUE_NONE, 'Run the installer even if YOURLS reports being already installed')
            ->setHelp(<<<'HELP'
The <info>%command.name%</info> command installs YOURLS from the command line:

  <info>php %command.full_name%</info>

It creates the table structure by running the Doctrine migrations found in includes/Migrations,
then seeds the options table and the sample short URLs, exactly like the web installer at
/admin/install.php does.

The command reads your existing user/config.php: make sure YOURLS_DB_* and YOURLS_SITE are set
before running it.
HELP);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int {
        $io = new SymfonyStyle($input, $output);
        $io->title('Installing YOURLS');

        /* bin/console boots without touching the database, so read the options now: that is what
         * sets the "is YOURLS installed" flag. On a fresh database this simply finds nothing.
         */
        yourls_get_all_options();

        if (yourls_is_installed() && !$input->getOption('force')) {
            $io->warning('YOURLS is already installed. Re-run with --force to install anyway.');

            return Command::SUCCESS;
        }

        $errors = Installer::check_prerequisites();
        if ($errors !== []) {
            $io->error($errors);

            return Command::FAILURE;
        }

        if (!$input->getOption('skip-htaccess')) {
            if (yourls_create_htaccess()) {
                $io->success('File .htaccess successfully created/updated.');
            } else {
                $io->warning('Could not write the .htaccess file in the YOURLS root directory. You will have to do it manually, see https://yourls.org/htaccess');
            }
        }

        $result = Installer::install();

        foreach ($result['success'] as $message) {
            $io->writeln(' <info>✓</info> '.strip_tags($message));
        }

        if ($result['error'] !== []) {
            $io->error(array_map('strip_tags', $result['error']));

            return Command::FAILURE;
        }

        $io->success('YOURLS installed. Admin area: '.yourls_admin_url());

        return Command::SUCCESS;
    }

}
