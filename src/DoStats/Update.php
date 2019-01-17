<?php

namespace Balsama\DoStats;

use Gitonomy\Git\Repository;
use Gitonomy\Git\Admin;
use GuzzleHttp\Client;
use Psr\Http\Message\ResponseInterface;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Process\Process;
use Symfony\Component\Console\Helper\ProgressBar;
use Symfony\Component\Console\Output\ConsoleOutput;
use MathieuViossat\Util\ArrayToTextTable;
use Symfony\Component\Yaml;

class Update {

    /* @var \GuzzleHttp\ClientInterface */
    protected $client;

    /* @var string */
    protected $base_url = 'https://www.drupal.org/api-d7/node/';

    /* @var array */
    protected $issue_data = [];

    /* @var array (int) */
    protected $issue_numbers;

    /* @var $fs \Symfony\Component\Filesystem\Filesystem */
    protected $fs;

    /* @var string[] */
    protected $logCommandOptions = [];

    /* @var string[] */
    protected $committers = [];

    /**
     * A list of all repos to scan for commits along with the branch.
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

    /* @var \Symfony\Component\Console\Output\Output */
    protected $output;

    /* @var \Symfony\Component\Console\Helper\ProgressBar */
    protected $progressBar;

    public function __construct()
    {
        $this->getConfig();
        $this->createProgressBar();
        $this->output = new ConsoleOutput();
        $this->client = new Client();
        $this->fs = new Filesystem();
        $this->cloneAndUpdateRepos();
        $this->generateLog();
        $this->issue_numbers = $this->getIssueNumbers('log.txt');
        $this->gatherAllIssueData();
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
     * @return array
     *   Arroy of data about the issues in the log.
     */
    public function getAllIssueData() {
        return $this->issue_data;
    }

    /**
     * Finds issue numbers from Drupal commit messages.
     *
     * @param string $git_log
     *   Full path to a file containing git log of a repo with D.O formatted
     *   commit messages.
     *
     * @return array
     *   An array of issue numbers contained in the Class git_log.
     *
     * @throws \HttpInvalidParamException
     *   If no issue numbers are found.
     */
    protected function getIssueNumbers($git_log) {
        $blob = file_get_contents($git_log);
        preg_match_all('/ Issue #[0-9.]*/', $blob, $matches);
        if (empty($matches)) {
            throw new \HttpInvalidParamException('Cannot find any issue numbers in commit log.');
        }
        $issue_numbers = [];
        foreach ($matches[0] as $match) {
            $issue_numbers[] = substr($match, 8);
        }
        return $issue_numbers;
    }

    /**
     * Gets and stores issue data about all issue numbers.
     */
    protected function gatherAllIssueData() {
        $this->instantiateProgressBar(count($this->issue_numbers), 'Fetching data about issues');
        foreach ($this->issue_numbers as $issue_number) {
            $this->updateProgressBarWithDetail('Issue #' . $issue_number);
            $this->issue_data[] = $this->getIssueData($issue_number);
        }
        $this->progressBar->finish();
        $this->output->writeln('Finished fetching issue data');
    }

    /**
     * @param int $issue_number
     *   A valid D.O project issue number.
     *
     * @return array
     *   An array of information about the issue.
     */
    protected function getIssueData($issue_number) {
        $response = $this->client->get($this->base_url . $issue_number . '.json');
        $body = json_decode($response->getBody());
        $issue_data = [
            'Closed' => date('Y-m-d', $body->field_issue_last_status_change),
            'Title' => $this->truncate($body->title, 100),
            'Issue ID' => $body->nid,
            'Category' => $this->mapCategory($body->field_issue_category),
            'Size' => $this->mapSizeFromCommentCount(count($body->comments)),
            'Project' => $body->field_project->machine_name,
        ];
        return $issue_data;
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

    /**
     * Summarizes issue data into # of points per category.
     *
     * @return string
     *   Summarized issue data.
     */
    protected function summarizeIssueData() {
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
        return "\n" . 'Feature points: ' . $features_points . "\n" . 'Maintenance points: ' . $maintenance_points . "\n" . 'Other points: ' . $other_points . "\n";
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
     * Clones local copies of the repos and checks out the defined branch.
     */
    protected function cloneAndUpdateRepos() {
        $this->fs->mkdir('./repos');
        $this->instantiateProgressBar(count($this->repos_to_scan), 'Setting up repos');
        foreach ($this->repos_to_scan as $name => $info) {
            $this->updateProgressBarWithDetail($name);
            if (!$this->fs->exists('./repos/' . $name)) {
                $this->output->writeln("$name is new. Cloning.");
                Admin::cloneTo('./repos/' . $name, $info['url'], false);
            }
            $this->repos[$name] = new Repository('./repos/' . $name);
            $this->repos[$name]->run('fetch');
            $this->repos[$name]->run('checkout', [$info['branch']]);
            $this->repos[$name]->run('pull');
        }
        $this->progressBar->finish();
        $this->output->writeln('Finished setting up repos');
    }

    /**
     * Creates the git log based on the repos and committers.
     */
    protected function generateLog() {
        if ($this->fs->exists('log.txt')) {
            $this->fs->remove('log.txt');
        }
        $this->fs->touch('log.txt');
        $this->setLogCommandOptions();

        $this->instantiateProgressBar(count($this->repos_to_scan), 'Writing git log for repos:');

        foreach ($this->repos_to_scan as $name => $info) {
            $this->appendGitLog($name);
            $this->progressBar->advance();
        }

        $this->progressBar->finish();
        $this->output->writeln('Done writing git logs');
    }

    /**
     * Adds the give repo's log output to the log.txt file.
     *
     * @param $repo string
     *   The name of the repo
     */
    protected function appendGitLog($repo) {
        $process = new Process($this->logCommandOptions, './repos/' . $repo);
        $process->run();
        $log = $process->getOutput();
        $this->fs->appendToFile('log.txt', $log);
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

    protected function createProgressBar() {
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
     * Gets the config from yaml files.
     */
    protected function getConfig() {
        $yaml = new Yaml\Yaml();
        $this->committers = $yaml::parseFile('./config/committers.yml');
        $this->repos_to_scan = $yaml::parseFile('./config/repos.yml');
        $this->date_range = $yaml::parseFile('./config/date.range.yml');
    }

}