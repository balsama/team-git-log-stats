<?php

namespace Balsama\Command;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Balsama\DoStats\GitLogStats;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Console\Helper\TableSeparator;

class StatsCommand extends Command
{

    protected static $defaultName = 'run:stats';
    private SymfonyStyle $io;

    /**
     * @param InputInterface $input
     * @param OutputInterface $output
     *
     * @return void
     */
    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $this->io = new SymfonyStyle($input, $output);
        $this->validateOptions($input, $output);
        $update = new GitLogStats($input->getOptions());

        $this->summarizeConfig($update->getConfig());

        $update->execute();

        $csv = $update->getCsv();

        $output->write($csv);

        $foo = 21;


        return;
    }

    /**
     * Adds the year + week/quarter options to the command.
     */
    protected function configure()
    {
        $description = <<<EOT
        Parses Git logs for commits by specific people over a set time and formats the results into a table and summary.
        EOT;
        $this->setDescription($description);
        $help = <<<EOT
        Parses Git logs for commits by specific people over a set time and formats the results into a table and summary.
        EOT;
        $this->setHelp($help);
        $description = <<<EOT
        The year for the report (the full numeric representation of a year, 4 digits). If this option is passed, you 
        must also include either the --month or --week option and associated value.
        EOT;
        $this->addOption(
            'year',
            'y',
            InputOption::VALUE_REQUIRED,
            $description,
        );
        $description = <<<EOT
        The quarter for the report (1, 2, 3, or 4). If this option is passed, you must also include the --year option 
        and value, and you may not pass the --week option.
        EOT;
        $this->addOption(
            'quarter',
            'Q',
            InputOption::VALUE_REQUIRED,
            $description,
        );
        $description = <<<EOT
        The week for the report (in ISO-8601 format, E.g. 1, 6, 26, or 52). If this option is passed, you must also 
        include the --year option and value, and you may not pass the --quarter option.
        EOT;
        $this->addOption(
            'week',
            null,
            InputOption::VALUE_REQUIRED,
            $description,
        );
        $this->addOption(
            'use-date-config',
            null,
            InputOption::VALUE_NONE,
            "Use the config in /config/date.range.yml."
        );
    }

    /**
     * Validates the provided options and their formats.
     *
     * E.g., week must also include year and cannot include a quarter; and it
     * must be a number between 1 - 52.
     *
     * @param InputInterface $input
     * @param OutputInterface $output
     *
     * @throws \Exception
     *
     * @return void
     */
    protected function validateOptions($input, $output)
    {
        $options = $input->getOptions();
        if ($options['use-date-config']) {
            $output->writeln('Using config for dates. Ignoring all other options.');
            return;
        } elseif ($options['quarter']) {
            if ($options['week']) {
                // Week and quarter would conflict with each other.
                throw new \Exception('You can only pass one of --quarter and --week argument, not both.');
            }
            if (!in_array($options['quarter'], [1,2,3,4])) {
                throw new \Exception('Quarter option must be a single integer, 1, 2, 3, or, 4');
            }
            $this->validateYear($options['year']);
        } elseif ($options['week']) {
            if ($options['quarter']) {
                // Quarter and week would conflict with each other.
                throw new \Exception('You can only pass one of --week and --quarter argument, not both');
            }
            if ((!is_numeric($options['week'])) || ($options['week'] < 1) || ($options['week'] > 53)) {
                throw new \Exception('Week option must be a number between 1 and 52');
            }
            $this->validateYear($options['year']);
        } elseif ($options['year']) {
            $this->validateYear($options['year']);
            if ((empty($options['quarter'])) && empty($options['week'])) {
                throw new \Exception('Year option must also be passed with either week or quarter');
            }
        }

        return;
    }

    /**
     * Validates the param is four-digit integer.
     * @param $year
     * @throws \Exception
     */
    protected function validateYear($year)
    {
        if (preg_match("/^[0-9]{4}$/", $year)) {
            return;
        }
        throw new \Exception('Year option must be a four digit integer');
    }

    private function summarizeConfig($config)
    {
        $this->io->title('Date Config');
        $this->io->definitionList(
            [array_keys($config['date_range'])[0] => $config['date_range'][array_keys($config['date_range'])[0]]],
            [array_keys($config['date_range'])[1] => $config['date_range'][array_keys($config['date_range'])[1]]],
            [array_keys($config['date_range'])[2] => $config['date_range'][array_keys($config['date_range'])[2]]],
            [array_keys($config['date_range'])[3] => $config['date_range'][array_keys($config['date_range'])[3]]],
        );
        $this->io->title('Contributors');
        $this->io->listing($config['committers']);
        $this->io->title('Repos');
        $this->io->listing(array_keys($config['repos_to_scan']));

        $confirm = $this->io->confirm('Do you want to continue with this config?');
        if (!$confirm) {
            $this->io->warning('Cancelled');
            exit(1);
        }
    }
}
