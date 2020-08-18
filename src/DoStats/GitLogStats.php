<?php

namespace Balsama\DoStats;

use Gitonomy\Git\Repository;
use Gitonomy\Git\Admin;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\ServerException;
use MathieuViossat\Util\ArrayToTextTable;
use Psr\Http\Message\ResponseInterface;
use Symfony\Component\Console\Exception\InvalidArgumentException;
use Symfony\Component\Console\Helper\ProgressBar;
use Symfony\Component\Console\Output\ConsoleOutput;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Process\Process;
use Symfony\Component\Yaml;

class GitLogStats {

    /* @var \GuzzleHttp\ClientInterface */
    protected $client;

    /* @var $fs \Symfony\Component\Filesystem\Filesystem */
    protected $fs;

    /* @var \Symfony\Component\Console\Helper\ProgressBar */
    protected $progressBar;

    /* @var string */
    protected $base_url = 'https://www.drupal.org/api-d7/';

    /* @var array */
    protected $issue_data = [];

    /* @var array (int) */
    protected $issue_numbers;

    /* @var string[] */
    protected $logCommandOptions = [];

    /* @var string */
    protected $log;

    /**
     * @var array
     *   List of arguments that were passed to the script.
     */
    protected $arguments;

    /**
     * Arrays of contributor names keyed by the issue to which they contributed.
     * E.g.:
     * [12345] => ['user1', 'user2]
     *
     * @var array[]
     */
    protected $issueCreditsByContributor = [];

    /**
     * Arrays of issue numbers keyed by the contributor.
     * E.g:
     * ['username'] => [1234, 5678]
     *
     * @var array[]
     */
    protected $contributorIssues = [];

    /**
     * Arrays of contributor name, issue count, and total points suitable for
     * passing off to a table formatter.
     *
     * @var array[]
     */
    protected $contributorPoints = [];

    /* @var int */
    protected $apiRequestCount = 0;

    /**
     * An array of committer usernames.
     *
     * @var string[]
     */
    protected $committers = [];

    /**
     * A list of all repos to scan for commits along with the branch.
     *
     * @var array
     */
    protected $repos_to_scan = [];

    /**
     * Date range for commits.
     * @var array
     *
     * Array format:
     * ['after' => 'Y-M-d', 'before' => Y-M-d']
     */
    protected $date_range;

    /**
     * An array of Repository objects to scan for commits.
     *
     * @var \Gitonomy\Git\Repository []
     */
    protected $repos = [];

    /**
     * The config provided in YAML that a debugger might be interested in.
     */
    protected $config = [];

    public function __construct($arguments) {
        $this->arguments = $arguments;
        $this->setupTools();
        $this->parseConfig();
        $this->initProgressBar();
        $this->cloneAndUpdateRepos();
        $this->generateLog();
        $this->gatherAllIssueData();
        $this->calculateContributorPoints();
    }

    /**
     * @return string
     *   The date range used to generate the report.
     */
    public function getDateRange() {
        $string = 'Week/Month/From: ' . array_values($this->date_range)[0] . ' Year/To: ' . array_values($this->date_range)[1];
        $strlen = strlen($string);
        $separator = str_repeat('*', ($strlen + 4));
        return $separator . "\n* " . $string . " *\n" . $separator . "\n";
    }

    /**
     * @return string
     *   A formatted table of all issues.
     */
    public function getTable() {
        return $this->formatAllIssueData();
    }

    /**
     * @return string
     *   A summary of issues and story points.
     */
    public function getSummary() {
        return $this->summarizeIssueData();
    }

    /**
     * @return string
     */
    public function getCreditTable() {
        return $this->formatCreditData();
    }
    /**
     * @return array
     *   Array of data about the issues in the log.
     */
    public function getAllIssueData() {
        return $this->issue_data;
    }

    /**
     * @return string
     */
    public function getApiRequestCount() {
        return "\n Total API Requests: $this->apiRequestCount \n";
    }

    /**
     * Clones local copies of the repos and checks out the defined branch.
     */
    protected function cloneAndUpdateRepos() {
        $this->fs->mkdir('./repos');
        $this->instantiateProgressBar(count($this->repos_to_scan), 'Setting up repos');
        foreach ($this->repos_to_scan as $name => $info) {
            $this->updateProgressBarWithDetail($name);
            if (!$this->fs->exists('./repos/' . $name)) {
                Admin::cloneTo('./repos/' . $name, $info['url'], false);
            }
            $this->repos[$name] = new Repository('./repos/' . $name);
            $this->repos[$name]->run('fetch');
            $this->repos[$name]->run('checkout', [$info['branch']]);
            $this->repos[$name]->run('pull');
        }
        $this->progressBar->finish();
    }

    public function getConfig() {
        return $this->config;
    }

    /**
     * Creates the git log based on the repos and committers.
     */
    protected function generateLog() {
        $this->setLogCommandOptions();

        $this->instantiateProgressBar(count($this->repos_to_scan), 'Writing git log for repos:');

        foreach ($this->repos_to_scan as $name => $info) {
            $this->appendGitLog($name);
            $this->progressBar->advance();
        }

        $this->progressBar->finish();
    }

    /**
     * Finds issue numbers from Drupal commit messages.
     *
     * @return array
     *   An array of issue numbers contained in the Class git_log.
     *
     * @throws \HttpInvalidParamException
     *   If no issue numbers are found.
     */
    protected function getIssueNumbers() {
        $blob = $this->log;
        preg_match_all('/ Issue #[0-9.]*/', $blob, $matches);
        if (empty($matches)) {
            throw new \HttpInvalidParamException('Cannot find any issue numbers in commit log.');
        }
        $issue_numbers = [];
        foreach ($matches[0] as $match) {
            $issue_numbers[] = substr($match, 8);
        }
        return array_unique($issue_numbers);
    }

    /**
     * Gets and stores issue data about all issue numbers.
     */
    protected function gatherAllIssueData() {
        $this->issue_numbers = $this->getIssueNumbers($this->log);
        $this->instantiateProgressBar(count($this->issue_numbers), 'Fetching data about issues');
        foreach ($this->issue_numbers as $issue_number) {
            $this->updateProgressBarWithDetail('Issue #' . $issue_number);
            $this->issue_data[$issue_number] = $this->getIssueData($issue_number);
        }
        $this->progressBar->finish();
    }

    /**
     * @param int $issue_number
     *   A valid D.O project issue number.
     *
     * @return array
     *   An array of information about the issue.
     */
    protected function getIssueData($issue_number) {
        $response = $this->apiRequest('node', $issue_number);
        $body = json_decode($response->getBody());
        $contributors = $this->getIssueContributors($issue_number);
        $truncatedContributors = [];
        foreach ($contributors as $contributor) {
            $truncatedContributors[] = substr($contributor, 0, 2);
        }
        if ($body->type != 'project_issue') {
            // Handle commit messages which might point to non-project_issues.
            return [
                'Closed' => 'unknown',
                'Title' => $this->truncate($body->title, 100),
                'ID' => $body->nid,
                'Category' => 'Other',
                'Size' => $this->mapSizeFromCommentCount(count($body->comments)),
                'Project' => 'unknown',
            ];
        }
        return [
            'Closed' => date('Y-m-d', $body->field_issue_last_status_change),
            'Title' => $this->truncate($body->title, 100),
            'ID' => $body->nid,
            'Category' => $this->mapCategory($body->field_issue_category),
            'Size' => $this->mapSizeFromCommentCount(count($body->comments)),
            'Project' => $body->field_project->machine_name,
            'Contributors' => implode(', ', $truncatedContributors),
        ];
    }

    /**
     * Returns a list of contributor names filtered by the committers.yml for a
     * given issue and populates the issue and contributor variables.
     *
     * @param $parentIssue
     *   The issue ID to which the comments belong.
     * @return array
     */
    protected function getIssueContributors($parentIssue) {
        $commitMessage = $this->getCommitMessageFromIssueNumber($parentIssue);
        foreach ($this->committers as $contributor) {
            if (strpos($commitMessage, $contributor)) {
                $contribitors[] = $contributor;
                $this->issueCreditsByContributor[$parentIssue][] = $contributor;
                $this->contributorIssues[$contributor][] = $parentIssue;
            }

        }
        return $contribitors;
    }

    /**
     * Helper function to return the calculated size of an issue from the
     * populated `issue_data` property.
     *
     * @param int $issueId
     * @return int
     */
    protected function getIssuePoints($issueId) {
        return $this->issue_data[$issueId]['Size'];
    }

    /**
     * Formats issue data into an ASCII table.
     *
     * @return string
     *   Issue data in an ASCII table.
     *
     * @throws \Exception
     */
    protected function formatAllIssueData() {
        if (empty($this->issue_data)) {
            throw new \Exception("No issue data collected yet. Perhaps you called this method before ::getIssueData?");
        }
        $renderer = new ArrayToTextTable($this->issue_data);
        return $renderer->getTable();
    }


    protected function formatCreditData() {
        $renderer = new ArrayToTextTable($this->contributorPoints);
        return $renderer->getTable();
    }
    /**
     * Summarizes issue data into # of points per category.
     *
     * @return string
     *   Summarized issue data.
     */
    protected function summarizeIssueData() {
        $issue_count = count($this->issue_numbers);
        $features_points = 0;
        $maintenance_points = 0;
        $other_points = 0;
        foreach ($this->issue_data as $issue_datum) {
            if ($issue_datum['Category'] == 'Feature') {
                $features_points = ($features_points + (int) $issue_datum['Size']);
            }
            elseif ($issue_datum['Category'] == 'Maintenance') {
                $maintenance_points = ($maintenance_points + (int) $issue_datum['Size']);
            }
            elseif ($issue_datum['Category'] == 'Other') {
                $other_points = ($other_points + (int) $issue_datum['Size']);
            }
        }
        return "\n" . 'Issues: ' . $issue_count . "\n" . 'Feature points: ' . $features_points . "\n" . 'Maintenance points: ' . $maintenance_points . "\n" . 'Other points: ' . $other_points . "\n";
    }

    /**
     * Wrapper function around D.O API requests. Recursive to account for random
     * 500s.
     *
     * @param string $entityType
     *   E.g., 'node', or 'comment'
     * @param int $entityId
     * @param bool $retryOnError
     *   Set to false to throw an exception on 500 errors.
     *
     * @return object
     */
    protected function apiRequest($entityType, $entityId, $retryOnError = true) {
        try {
            $response = $this->client->get($this->base_url . $entityType . '/' . $entityId . '.json');
            $this->apiRequestCount++;
            return $response;
        }
        catch (ServerException $e) {
            if ($retryOnError) {
                return $this->apiRequest($entityType, $entityId);
            }
            echo 'Caught response: ' . $e->getResponse()->getStatusCode();
        }
    }

    /**
     * Decodes a JSON response.
     *
     * @param \Psr\Http\Message\ResponseInterface $response
     *   The response object.
     *
     * @return mixed
     *   The decoded response data. If the JSON parser raises an error, the test
     *   will fail, with the bad input as the failure message.
     *
     * @throws \HttpResponseException
     *   If the body doesn't contain an error.
     */
    protected function decodeResponse(ResponseInterface $response)
    {
        $body = (string) $response->getBody();
        if (json_last_error() === JSON_ERROR_NONE) {
            return $body;
        }
        else {
            throw new \HttpResponseException("Bad response");
        }
    }

    /**
     * Maps a D.O issue category ID to either "Maintenance" or "Feature".
     *
     * @param int $category
     *   The D.O issue category:
     *   - 1: Bug             => Maintenance
     *   - 2: Task            => Feature
     *   - 3: Feature Request => Feature
     *   - 4: Support Request => Maintenance
     *   - 5: Plan            => Feature
     *
     * @return string
     */
    protected function mapCategory($category) {
        $map = [
            'Maintenance' => [1, 4],
            'Feature' => [2, 3, 5],
        ];
        if (in_array($category, $map['Maintenance'])) {
            return 'Maintenance';
        }
        elseif (in_array($category, $map['Feature'])) {
            return 'Feature';
        }
        return 'Other';
    }

    /**
     * Attempts to determine the "size" (effort/points) of an issue based on the
     * number of comments it has.
     *
     * @param int $count
     *   The numbed of comments on an issue.
     *
     * @return int
     *   The mapped "size".
     */
    protected function mapSizeFromCommentCount($count) {
        switch ($count) {
            case ($count > 100):
                $size = 21;
                break;
            case ($count > 50):
                $size = 13;
                break;
            case ($count > 25):
                $size = 8;
                break;
            case ($count > 10):
                $size = 5;
                break;
            default:
                $size = 3;
        }
        return $size;
    }

    /**
     * Adds the give repo's log output to the $this->log.
     *
     * @param $repo string
     *   The name of the repo
     */
    protected function appendGitLog($repo) {
        $process = new Process($this->logCommandOptions, './repos/' . $repo);
        $process->run();
        $log = $process->getOutput();
        $this->log .= $log;
    }

    /**
     * Generates the git log command and options.
     * @return array
     */
    protected function setLogCommandOptions() {
        $base = ['git', 'log', '--oneline'];
        $date = ['--after=' . $this->date_range['after'], '--before=' . $this->date_range['before']];
        $committers = [];
        foreach ($this->committers as $committer) {
            $committers[] = '--grep=' . $committer;
        }
        $this->logCommandOptions = array_merge($base, $date, $committers);
    }

    /**
     * Helper function to truncate a stringth at a given length and add an
     * elipsis at the end if it was truncated.
     *
     * @param $string string
     * @param $length int
     * @return string
     */
    protected function truncate($string, $length) {
        if (strlen($string) > $length) {
            $string = substr($string, 0, $length) . '...';
        }
        return $string;
    }

    protected function initProgressBar() {
        $output = new ConsoleOutput();
        $this->progressBar = new ProgressBar($output);
        $this->progressBar->setFormatDefinition('custom', "\n%message% \n %current%/%max% |%bar%| \n %detail% \n");
        $this->progressBar->setFormat('custom');
    }

    /**
     * Wrapper function around Symfony Progress Bar instatiation methods.
     *
     * @param $count int
     * @param $message string
     */
    protected function instantiateProgressBar($count, $message) {
        $this->progressBar->setMessage($message);
        $this->progressBar->setMessage('', 'detail');
        $this->progressBar->setMaxSteps($count);
        $this->progressBar->start();
    }

    /**
     * Wrapper function around progress bar update methods.
     * @param $detail string
     */
    protected function updateProgressBarWithDetail($detail) {
        $this->progressBar->setMessage($detail, 'detail');
        $this->progressBar->advance();
    }

    /**
     * Parses the config from yaml files.
     */
    protected function parseConfig() {
        $yaml = new Yaml\Yaml();
        $this->committers = $yaml::parseFile('./config/committers.yml');
        $this->repos_to_scan = $yaml::parseFile('./config/repos.yml');
        $dates = $yaml::parseFile('./config/date.range.yml');
        $this->date_range = $this->parseDateRange($dates);

        $this->config =[
            'committers' => $this->committers,
            'repos_to_scan' => $this->repos_to_scan,
        ];
    }

    /**
     * Parses the start and end time of the git logs were interested in from one
     * of three formats.
     * @param $dates array
     *   An array of before and after or year and quarter or week and year values.
     * @return mixed
     *   Normalized array of after and before timestamps.
     */
    protected function parseDateRange($dates) {
        $date_keys = array_keys($dates);

        if ($this->arguments['use-date-config'] === false) {
            // If `use-date-config` hasn't explicitly been set to true, use the
            // options passed if they exist.
            if ($this->arguments['week']) {
                // If a specific week and year have been passed as arguments,
                // use those instead of what's in config.
                $date_keys = ['week', 'year'];
                $dates['year'] = $this->arguments['year'];
                $dates['week'] = $this->arguments['week'];
            }
            if ($this->arguments['quarter']) {
                // Same for a specific quarter and year
                $date_keys = ['quarter', 'year'];
                $dates['year'] = $this->arguments['year'];
                $dates['quarter'] = $this->arguments['quarter'];
            }
        }

        if ($date_keys == ['after', 'before']) {
            return $dates;
        }
        elseif ($date_keys == ['quarter', 'year']) {
            if (!is_numeric($dates['quarter']) || (!is_numeric($dates['year']))) {
                throw new InvalidArgumentException('Year and quarter values must both be numeric.');
            }
            switch ($dates['quarter']) {
                case 1:
                    $dates['after'] = ($dates['year'] - 1) . '-12-31';
                    $dates['before'] = $dates['year'] . '-04-01';
                    break;
                case 2:
                    $dates['after'] = $dates['year'] . '-03-31';
                    $dates['before'] = $dates['year'] . '-07-01';
                    break;
                case 3:
                    $dates['after'] = $dates['year'] . '-06-30';
                    $dates['before'] = $dates['year'] . '-10-01';
                    break;
                case 4:
                    $dates['after'] = $dates['year'] . '-09-30';
                    $dates['before'] = ($dates['year'] + 1) . '-01-01';
                    break;
            }
            $dates['after'] = $this->handleTimezone($dates['after']);
            $dates['before'] = $this->handleTimezone($dates['before']);
            return $dates;
        }
        elseif ($date_keys == ['week', 'year']) {
            $after_time = $dates['year'] . $this->formatWeekNumber($dates['week']);
            $before_time = $dates['year'] . $this->formatWeekNumber($dates['week'], TRUE);

            $dates['after'] = date('Y-m-d', strtotime($after_time));
            $dates['before'] = date('Y-m-d', strtotime($before_time));

            $dates['after'] = $this->handleTimezone($dates['after']);
            $dates['before'] = $this->handleTimezone($dates['before']);
            return $dates;
        }
        else {
            throw new InvalidArgumentException('Date config must provide either "before" and "after",  "quarter" and "year", or "week" and "year" values.');
        }
    }

    protected function handleTimezone($date, $format = 'U') {
        $dt = new \DateTime($date, new \DateTimeZone('UTC'));
        return $dt->format($format);
    }

    protected function setupTools() {
        $this->client = new Client();
        $this->fs = new Filesystem();
    }

    /**
     * @param int $week_number
     *   An ISO-8601 week number.
     * @param bool $following_week
     *   Return the week following the one provided by $week_number.
     *
     * @return string
     *   A week number suitible for passing to `strtotime`.
     *   Example:
     *     - Given $week_number = 5, $following_week = FALSE
     *         return 'W05'
     *     - Given $week_number = 15, $following_week = TRUE
     *         return 'W16'
     */
    protected function formatWeekNumber($week_number, $following_week = FALSE) {
        $week_number = (int) $week_number;
        if ($following_week) {
            $week_number++;
        }
        if ($week_number < 10) {
            $week_number = '0' . $week_number;
        }
        return (string) 'W' . $week_number;
    }

    /**
     * @param $issueNumber
     * @return string|false
     */
    private function getCommitMessageFromIssueNumber($issueNumber) {
        $lines = explode("\n", $this->log);
        foreach ($lines as $lineNumber => $line) {
            if (strpos($line, $issueNumber) !== false) {
                return $line;
            }
        }
        return -1;
    }

}