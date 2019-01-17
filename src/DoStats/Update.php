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

class Update {

    /* @var \GuzzleHttp\ClientInterface */
    protected $client;

    /* @var string */
    protected $base_url;

    /* @var int */
    protected $timestamp;

    /* @var array */
    protected $issue_data;

    /* @var array (int) */
    protected $issue_numbers;


    /* @var $fs \Symfony\Component\Filesystem\Filesystem */
    protected $fs;

    /* @var array (string) */
    protected $committers = [
        'plunkett',
        'plunkett',
        'mortenson',
        'phenaproxima',
        'DyanneNova',
        'Wim Leers',
        'drpal',
        'gabesullice',
        'balsama',
        'huzooka',
        'bendeguz.csirmaz',
    ];

    /**
     * A list of all repos to scan for commits along with the branch.
     * @var array
     */
    protected $repos_to_scan = [
        'claro' => [
            'url' => 'git@git.drupal.org:project/claro.git',
            'branch' => '8.x-1.x',
        ],
        'drupal' => [
            'url' => 'https://git.drupal.org/project/drupal.git',
            'branch' => '8.7.x',
        ],
        'lightning' => [
            'url' => 'git@git.drupal.org:project/lightning.git',
            'branch' => '8.x-3.x',
        ],
        'lightning_api' => [
            'url' => 'git@git.drupal.org:project/lightning_api.git',
            'branch' => '8.x-3.x',
        ],
        'lightning_core' => [
            'url' => 'git@git.drupal.org:project/lightning_core.git',
            'branch' => '8.x-3.x',
        ],
        'lightning_layout' => [
            'url' => 'git@git.drupal.org:project/lightning_layout.git',
            'branch' => '8.x-1.x',
        ],
        'lightning_media' => [
            'url' => 'git@git.drupal.org:project/lightning_media.git',
            'branch' => '8.x-3.x',
        ],
        'lightning_workflow' => [
            'url' => 'git@git.drupal.org:project/lightning_workflow.git',
            'branch' => '8.x-3.x',
        ],
        'js_admin' => [
            'url' => 'https://github.com/jsdrupal/drupal-admin-ui.git',
            'branch' => 'master',
        ]
    ];

    /**
     * An array of Repository objects to scan for commits.
     *
     * @var \Gitonomy\Git\Repository []
     */
    protected $repos = [];

    /**
     * The path to the git log of commits credited by our team. Generate it
     * with for each repo and merge the results:
     * `git log --oneline --after=2018-03-31 --before=2018-07-01 --grep="plunkett" --grep="plunkett" --grep="mortenson" --grep="phenaproxima" --grep="DyanneNova" --grep="Wim Leers" --grep="drpal" --grep="gabesullice" --grep="balsama" --grep="huzooka"`
     *
     * @var string
     */
    protected $git_log;

    protected $output;

    protected $progressBar;

    public function __construct()
    {
        $this->output = new ConsoleOutput();
        $this->progressBar = new ProgressBar($this->output);
        $this->progressBar->setFormatDefinition('custom', "\n%message% \n %current%/%max% |%bar%| \n\n");
        $this->progressBar->setFormat('custom');
        $this->client = new Client();
        $this->base_url = 'https://www.drupal.org/api-d7/node/';
        $this->timestamp = date('Y-m-d-i-s');
        $this->issue_data = [];
        $this->fs = new Filesystem();
        $this->fs->mkdir(getcwd()  . '/repos');
        $this->cloneAndUpdateRepos();
        $this->generateLog();
        $this->issue_numbers = $this->getIssueNumbers('log.txt');
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
    public function getIssueNumbers($git_log) {
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
    public function getAllIssueData() {
        $count = count($this->issue_numbers);
        $this->progressBar->setMaxSteps($count);
        $this->progressBar->setMessage('Fetching data about issues.');
        $this->progressBar->start($count);
        foreach ($this->issue_numbers as $issue_number) {
            $this->issue_data[] = $this->getIssueData($issue_number);
            $this->progressBar->advance();
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
    public function getIssueData($issue_number) {
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
    public function formatAllIssueData() {
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
    public function summarizeIssueData() {
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

    protected function truncate($string, $length) {
        if (strlen($string) > $length) {
            $string = substr($string, 0, $length) . '...';
        }
        return $string;
    }

    protected function cloneAndUpdateRepos() {
        $count = count($this->repos_to_scan);
        $this->progressBar->setMessage('Setting up repos.');
        $this->progressBar->setMaxSteps($count);
        $this->progressBar->start();
        foreach ($this->repos_to_scan as $name => $info) {
            if (!$this->fs->exists('./repos/' . $name)) {
                $this->output->writeln("$name is new. Cloning.");
                Admin::cloneTo('./repos/' . $name, $info['url'], false);
            }
            $this->repos[$name] = new Repository('./repos/' . $name);
            $this->repos[$name]->run('fetch');
            $this->repos[$name]->run('checkout', [$info['branch']]);
            $this->repos[$name]->run('pull');
            $this->progressBar->advance();
        }
        $this->progressBar->finish();
        $this->output->writeln('Finished setting up repos');
    }

    protected function generateLog() {
        if ($this->fs->exists('log.txt')) {
            $this->fs->remove('log.txt');
        }
        $this->fs->touch('log.txt');

        $count = count($this->repos_to_scan);
        $this->progressBar->setMessage('Writing git log for repos.');
        $this->progressBar->setMaxSteps($count);

        foreach ($this->repos_to_scan as $name => $info) {
            $this->appendGitLog($name);
            $this->progressBar->advance();
        }
        $this->progressBar->finish();
        $this->output->writeln('Done writing git logs');
    }

    /**
     * @param $repo string
     */
    protected function appendGitLog($repo) {
        $options = [
            'git',
            'log',
            '--oneline',
            '--after=2018-09-30',
            '--before=2019-01-01',
        ];
        foreach ($this->committers as $committer) {
            $options[] = '--grep=' . $committer;
        }

        $process = new Process($options, './repos/' . $repo);
        $process->run();
        $log = $process->getOutput();
        $this->fs->appendToFile('log.txt', $log);
    }

}