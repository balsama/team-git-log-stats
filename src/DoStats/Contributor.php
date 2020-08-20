<?php


namespace Balsama\DoStats;

use http\Exception\InvalidArgumentException;

class Contributor
{

    private string $username;
    private string $real_name;
    private string $primary_assignment;
    private float $assigned_to_core;
    private Fetch $fetch;

    public function __construct(
        string $username,
        string $real_name,
        string $primary_assignment,
        float $assigned_to_core
    ) {
        $this->username = $username;
        $this->real_name = $real_name;
        $this->primary_assignment = $primary_assignment;
        $this->assigned_to_core = $assigned_to_core;
        $this->fetch = new Fetch('https://www.drupal.org/api-d7/user.json?name=' . $this->username);

        $this->validateUsername();
    }

    public function getUsername()
    {
        return $this->username;
    }
    public function getRealName()
    {
        return $this->real_name;
    }
    public function getPrimaryAssignment()
    {
        return $this->primary_assignment;
    }
    public function getDrupalCoreAssignment()
    {
        return $this->assigned_to_core;
    }

    private function validateUsername()
    {
        $user = reset($this->fetch->fetch()->get()->list);
        if ($user->name != $this->username) {
            throw new InvalidArgumentException('Username ' . $this->username . 'not found');
        }
    }
}
