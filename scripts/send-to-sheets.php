<?php

use Balsama\DoStats\GitLogStats;

require './vendor/autoload.php';

if (php_sapi_name() != 'cli') {
    throw new Exception('This application must be run on the command line.');
}

/**
 * Returns an authorized API client.
 * @return Google_Client the authorized client object
 */
function getClient()
{
    $client = new Google_Client();
    $client->setApplicationName('D.O Stats');
    $client->setScopes(Google_Service_Sheets::SPREADSHEETS);
    $client->setAuthConfig('credentials.json');
    $client->setAccessType('offline');
    $client->setPrompt('select_account consent');

    // Load previously authorized token from a file, if it exists.
    // The file token.json stores the user's access and refresh tokens, and is
    // created automatically when the authorization flow completes for the first
    // time.
    $tokenPath = 'token.json';
    if (file_exists($tokenPath)) {
        $accessToken = json_decode(file_get_contents($tokenPath), true);
        $client->setAccessToken($accessToken);
    }

    // If there is no previous token or it's expired.
    if ($client->isAccessTokenExpired()) {
        // Refresh the token if possible, else fetch a new one.
        if ($client->getRefreshToken()) {
            $client->fetchAccessTokenWithRefreshToken($client->getRefreshToken());
        } else {
            // Request authorization from the user.
            $authUrl = $client->createAuthUrl();
            printf("Open the following link in your browser:\n%s\n", $authUrl);
            print 'Enter verification code: ';
            $authCode = trim(fgets(STDIN));

            // Exchange authorization code for an access token.
            $accessToken = $client->fetchAccessTokenWithAuthCode($authCode);
            $client->setAccessToken($accessToken);

            // Check to see if there was an error.
            if (array_key_exists('error', $accessToken)) {
                throw new Exception(join(', ', $accessToken));
            }
        }
        // Save the token to a file.
        if (!file_exists(dirname($tokenPath))) {
            mkdir(dirname($tokenPath), 0700, true);
        }
        file_put_contents($tokenPath, json_encode($client->getAccessToken()));
    }
    return $client;
}


// Get the API client and construct the service object.
$client = getClient();
$service = new Google_Service_Sheets($client);

$spreadsheetId = '1jZLrNhdn3Fdi_tjGp4Kq0J3vLfyzzlWU6S5aiN8XFkA';
$range = 'All!A1:G';

$options = [
    'year' => 2020,
    'week' => 42,
    'quarter' => null,
    'logo-only' => true,
    'no-interaction' => true,
    'use-date-config' => false,
    'contributors-file-name' => 'contributors-2019-q1.yml',
];
$update = new GitLogStats($options);
$update->execute();
$stats = $update->getMetaArray();
$allIssues = $update->getAllIssueData();
array_shift($stats);

$date = reset($stats)['Week (YYYY-MM-DD)'];
$visdata = [
    'Comments' => [],
    'Issues' => [],
    'Points Estimate' => [],
    'Assigned to Drupal core' => [],
];
$commentsTotal = 0;
$issuesTotal = 0;
$pointsTotal = 0;
$assignedToDrupalCore = 0;
foreach ($stats as $individualStats) {
    $values = ['values' => array_values($individualStats)];
    $conf = ["valueInputOption" => "RAW"];
    $data = new Google_Service_Sheets_ValueRange();
    $data->setValues($values);

    $service->spreadsheets_values->append($spreadsheetId, $range, $data, $conf);

    // Arrange for visualization
    $visdata['Comments'][] = $individualStats['Comment Count'];
    $visdata['Issues'][] = $individualStats['Issues Closed'];
    $visdata['Points Estimate'][] = $individualStats['Points Estimate'];
    $assignedToDrupalCore = $assignedToDrupalCore + $individualStats['Drupal Core Assignment'];

    $commentsTotal = $commentsTotal + $individualStats['Comment Count'];
}

$visdata['Assigned to Drupal core'][] = $assignedToDrupalCore;
foreach ($visdata as $name => $values) {
    array_unshift($values, $date);
    $range = $name . '!A1:N';
    $values = ['values' => $values];
    $data = new Google_Service_Sheets_ValueRange();
    $data->setValues($values);
    $conf = ["valueInputOption" => "RAW"];
    $service->spreadsheets_values->append($spreadsheetId, $range, $data, $conf);
}

$issuesTotal = count($allIssues);
$pointsTotal = array_sum(array_column($allIssues,'Size'));
$sumArray = [
    $date,
    $commentsTotal,
    $issuesTotal,
    $pointsTotal,
];
$range = 'Comments, Issues, & Points Total!A2:D';
$values = ['values' => $sumArray];
$data = new Google_Service_Sheets_ValueRange();
$data->setValues($values);
$conf = ["valueInputOption" => "RAW"];
$service->spreadsheets_values->append($spreadsheetId, $range, $data, $conf);

$sumArray = [
    $date,
    $commentsTotal / $assignedToDrupalCore,
    $issuesTotal / $assignedToDrupalCore,
    $pointsTotal / $assignedToDrupalCore,
];
$range = 'Comments, Issues, & Points by assigned dev!A2:D';
$values = ['values' => $sumArray];
$data = new Google_Service_Sheets_ValueRange();
$data->setValues($values);
$conf = ["valueInputOption" => "RAW"];
$service->spreadsheets_values->append($spreadsheetId, $range, $data, $conf);
