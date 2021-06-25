<?php

/**
 * Provide the URL to the raw Travis CI config file as an argument to this script. Make sure it includes a token param
 * if the repo is private. E.g.:
 *    php ./scripts/check-orca-jobs.php https://raw.githubusercontent.com/acquia/acquia_search/3.x/.travis.yml\?token\=VALIDTOKEN
 */

$config_url = $argv[1];

$raw_config = file_get_contents($config_url);
$config = yaml_parse($raw_config);

$jobs_to_check_for = [
    'ISOLATED_TEST_ON_NEXT_MINOR',
    'INTEGRATED_TEST_ON_NEXT_MINOR',
    'ISOLATED_TEST_ON_NEXT_MINOR_DEV',
    'INTEGRATED_TEST_ON_NEXT_MINOR_DEV',
    'ISOLATED_UPGRADE_TEST_TO_NEXT_MINOR',
    'INTEGRATED_UPGRADE_TEST_TO_NEXT_MINOR',
    'ISOLATED_UPGRADE_TEST_TO_NEXT_MINOR_DEV',
    'INTEGRATED_UPGRADE_TEST_TO_NEXT_MINOR_DEV'
];

$results = [];
$key = (array_key_exists('jobs', $config)) ? 'jobs' : 'matrix';

foreach ($jobs_to_check_for as $job_to_check_for) {
    $results[$job_to_check_for] = "❌";

    foreach ($config[$key]['allow_failures'] as $included_job) {
        if ($included_job['env'] == 'ORCA_JOB=' . $job_to_check_for) {
            $results[$job_to_check_for] = "🚼";
        }
    }

    foreach ($config[$key]['include'] as $included_job) {
        if ($included_job['env'] == 'ORCA_JOB=' . $job_to_check_for) {
            $results[$job_to_check_for] = "✅";
        }
    }
}

print_r($results);
