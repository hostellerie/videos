<?php

$_CONF = array();
require_once dirname(__DIR__) . '/autoload.php';

class Videos_TestProviderClient
{
    public function search($query, $parameters)
    {
        return array('video1');
    }

    public function videos($ids)
    {
        return array('video1' => array('id' => 'video1'));
    }

    public function channels($ids)
    {
        return array('channel1' => array('id' => 'channel1'));
    }

    public function getLastError()
    {
        return array();
    }
}

$provider = new Videos_YouTubeProvider(new Videos_TestProviderClient());
if (!($provider instanceof Videos_ProviderInterface)) {
    fwrite(STDERR, "YouTube provider does not implement provider contract.\n");
    exit(1);
}
if ($provider->search('test', array()) !== array('video1')) {
    fwrite(STDERR, "Provider search delegation failed.\n");
    exit(1);
}
$videos = $provider->videos(array('video1'));
if (!isset($videos['video1'])) {
    fwrite(STDERR, "Provider videos delegation failed.\n");
    exit(1);
}
$channels = $provider->channels(array('channel1'));
if (!isset($channels['channel1'])) {
    fwrite(STDERR, "Provider channels delegation failed.\n");
    exit(1);
}
if (!is_array($provider->getLastError())) {
    fwrite(STDERR, "Provider error contract failed.\n");
    exit(1);
}

echo "Provider contract: OK\n";
