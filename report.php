<?php

/**
 * Prints formatted table of all issues found in configured repositories
 * and summary to stdout.
 */

include_once 'vendor/autoload.php';

$update = new Balsama\DoStats\GitLogStats();

echo $update->getTable() . $update->getSummary() . $update->getCreditTable() . $update->getApiRequestCount();
