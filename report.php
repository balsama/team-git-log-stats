<?php

/**
 * Prints formatted table of all issues in $git_log and summary to stdout.
 * Requires a file containing Drupal-project-formatted commit messages to
 * instantiate the Update class.
 */

include_once 'vendor/autoload.php';

$update = new Balsama\DoStats\Update();

echo $update->getTable() . $update->getSummary();