<?php
$all_ra_cust = array_map('str_getcsv', file('CCI_RADash_Detailed.csv'));
$all_ra_cust = array_column($all_ra_cust, 3);

$acn_live = array_map('str_getcsv', file('ACN Live Production Applications.csv'));
$acn_live = array_column($acn_live, 1);
$acn_non_live = array_map('str_getcsv', file('ACN Non-Production Upgrades.csv'));
$acn_non_live = array_column($acn_non_live, 1);

$live_intersect = array_intersect($all_ra_cust, $acn_live);
$non_live_intersect = array_intersect($all_ra_cust, $acn_non_live);

foreach ($live_intersect as $app) {
    file_put_contents('acn-live-ra-cust.txt', $app . "\n", FILE_APPEND);
}
foreach ($non_live_intersect as $app) {
    file_put_contents('acn-non-live-ra-cust.txt', $app . "\n", FILE_APPEND);
}

$foo = 21;