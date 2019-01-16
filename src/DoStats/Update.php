<?php

namespace Balsama\DoStats;

use GuzzleHttp\Client;
use Psr\Http\Message\ResponseInterface;
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

    /* @var array (string) */
    protected $repos = [
        'Drupal' => 'https://git.drupal.org/project/drupal.git',
        'Lightning' => 'git@git.drupal.org:project/lightning.git',
        'Lightning API' => 'git@git.drupal.org:project/lightning_api.git',
        'Lightning Core' => 'git@git.drupal.org:project/lightning_core.git',
        'Lightning Layout' => 'git@git.drupal.org:project/lightning_layout.git',
        'Lightning Media' => 'git@git.drupal.org:project/lightning_media.git',
        'Lightning Workflow' => 'git@git.drupal.org:project/lightning_workflow.git',
        'Claro' => 'git@git.drupal.org:project/claro.git',
    ];

    /**
     * The path to the git log of commits credited by our team. Generate it
     * with for each repo and merge the results:
     * `git log --oneline --after=2018-03-31 --before=2018-07-01 --grep="plunkett" --grep="plunkett" --grep="mortenson" --grep="phenaproxima" --grep="DyanneNova" --grep="Wim Leers" --grep="drpal" --grep="gabesullice" --grep="balsama" --grep="huzooka"`
     *
     * @var string
     */
    protected $git_log;

    public function __construct($git_log)
    {
        $this->client = new Client();
        $this->base_url = 'https://www.drupal.org/api-d7/node/';
        $this->timestamp = date('Y-m-d-i-s');
        $this->issue_data = [];
        $this->git_log = $git_log;
        $this->issue_numbers = $this->getIssueNumbers($this->git_log);
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
        foreach ($this->issue_numbers as $issue_number) {
            $this->issue_data[] = $this->getIssueData($issue_number);
        }
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

}