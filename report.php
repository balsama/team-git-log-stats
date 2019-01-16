<?php

/**
 * Prints formatted table of all issues in $git_log and summary to stdout.
 * Requires a file containing Drupal-project-formatted commit messages to
 * instantiate the Update class.
 */

include_once 'vendor/autoload.php';

if (empty($argv[1])) {
    throw new InvalidArgumentException('You must pass file and path containing git logs to this script.');
}

$update = new Balsama\DoStats\Update($argv[1]);

$update->getAllIssueData();
$table = $update->formatAllIssueData();
$summary = $update->summarizeIssueData();

echo $table . $summary;