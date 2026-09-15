<?php

if (!isset($_CONF)) {
    die('This file cannot be used on its own.');
}

/**
 * YouTube implementation of the minimal external provider contract.
 *
 * The provider deliberately delegates to the existing client so 0.20.0 can
 * introduce the architectural boundary without changing transport behavior.
 */
class Videos_YouTubeProvider implements Videos_ProviderInterface
{
    private $client;

    public function __construct($client)
    {
        $this->client = $client;
    }

    public function search($query, $parameters)
    {
        return $this->client->search($query, $parameters);
    }

    public function videos($ids)
    {
        return $this->client->videos($ids);
    }

    public function channels($ids)
    {
        return $this->client->channels($ids);
    }

    public function getLastError()
    {
        return $this->client->getLastError();
    }
}
