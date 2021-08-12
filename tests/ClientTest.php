<?php

use PHPUnit\Framework\TestCase;

class ClientTest extends TestCase
{
    public function testAuthenticate(): void
    {
        $httpClient = new Symfony\Component\HttpClient\Psr18Client();
        $psr17Factory = new \Nyholm\Psr7\Factory\Psr17Factory();
        $logger = new Monolog\Logger('depoto');
        $depotoClient = new \Depoto\Client($httpClient, $psr17Factory, $psr17Factory, $logger);
        $depotoClient
            ->setUsername('test@depoto.cz')
            ->setPassword('besttest')
            ->authenticate();
    }
}