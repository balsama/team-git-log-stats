<?php


namespace Balsama\DoStats;

use Balsama\Fetch;

class CommentScraper
{

    private string $username;
    private string $direction;
    private string $baseUrl = 'https://www.drupal.org/api-d7/comment.json?';
    private string $url;
    private array $params;
    private int $weekStart;
    private int $weekEnd;
    private array $comments = [];

    public function getComments()
    {
        return $this->comments;
    }

    public function __construct($username, int $year = null, int $week = null, $direction = 'DESC')
    {
        $this->validateUsername($username);
        $this->direction = $direction;
        $this->params = [
            'name' => $this->username,
            'sort' => 'created',
            'direction' => $this->direction,
        ];
        $this->url = $this->buildUrl();
        $this->setStartAndEndDate($year, $week);
    }

    public function fetch() {
        return Fetch::fetch($this->url);
    }

    public function fetchAll() {
        $commentResponse = $this->fetch();
        if ($commentResponse->next) {
            $this->url = $this->fixUrl($commentResponse->next);
        }

        $lastTimestamp = end($commentResponse->list)->created;

        foreach ($commentResponse->list as $comment) {
            if (($comment->created >= $this->weekStart) && ($comment->created <= $this->weekEnd)) {
                $this->comments[$comment->created] = $comment;
            }
        }

        if ($lastTimestamp < $this->weekStart) {
            return $this->comments;
        }
        $this->fetchAll();
    }

    public function continue($firstTimestamp, $lastTimestamp, $weekStart, $weekEnd, $potentialComments) {

    }

    private function validateUsername($username) {
        $this->username = $username;
        return true;
    }

    private function buildUrl() {
        return $this->baseUrl . http_build_query($this->params);
    }

    private function setStartAndEndDate($year, $week) {
        if (empty($year)) {
            $year = date('Y');
        }
        if (empty($week)) {
            $week = date('W');
        }
        $dto = new \DateTime();
        $this->weekStart = $dto->setISODate($year, $week)->format('U');
        $this->weekEnd = $dto->modify('+6 days')->format('U');
    }

    private function fixUrl($url) {
        return str_replace('/comment?', '/comment.json?', $url);
    }
}