<?php

namespace Balsama\Command;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Balsama\DoStats\GitLogStats;

class ReportCommand extends Command {
    // the name of the command (the part after "bin/console")
    protected static $defaultName = 'do:report';

    protected function configure() {
        $this->setDescription('Foos the bar.');
        $this->setHelp('This command lets you foo');
        $this->addOption('month');
        $this->addArgument('year');
    }

    protected function execute(InputInterface $input, OutputInterface $output) {
        //$stats = new GitLogStats(['foo']);
        $args = $input->getArguments();
        foreach ($args as $arg) {
            $output->writeln($arg);
        }
        $output->writeln('Hello world');
    }
}