<?php

/**
 * Prints formatted table of all issues found in configured repositories
 * and summary to stdout.
 */

include_once 'vendor/autoload.php';

use Symfony\Component\Console\Application;
use Balsama\Command\ReportCommand;

//$update = new GitLogStats($argv);

$application = new Application();
$application->add(new ReportCommand());
$application->run();


//echo $update->getDateRange() . $update->getTable() . $update->getSummary() . $update->getCreditTable() . $update->getApiRequestCount();
