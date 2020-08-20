<?php

namespace Balsama\DoStats;

use GuzzleHttp\Client;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\ServerException;
use Psr\Http\Message\ResponseInterface;

class Fetch
{
    private string $url;
    private int $requestLimit;
    private ClientInterface $client;
    private $fetchedData;

    public function __construct($url, $requestLimit = 0)
    {
        $this->url = $url;
        $this->requestLimit = $requestLimit;
        $this->client = new Client();
    }

    public function fetch($retryOnError = 5)
    {
        try {
            /* @var $response ResponseInterface $response */
            $response = $this->client->get($this->url);
            $body = json_decode($response->getBody());
            $this->fetchedData = $body;
            return $this;
        } catch (ServerException $e) {
            if ($retryOnError) {
                $retryOnError--;
                usleep(250000);
                return $this->fetch($retryOnError);
            }
            echo 'Caught response: ' . $e->getResponse()->getStatusCode();
        }
    }

    public function get()
    {
        return $this->fetchedData;
    }
}
