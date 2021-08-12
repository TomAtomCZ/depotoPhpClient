<?php

namespace Depoto;

use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Log\LoggerInterface;
use Psr\SimpleCache\CacheInterface;

class ClientFactory
{
    protected ClientInterface $httpClient;
    protected RequestFactoryInterface $requestFactory;
    protected StreamFactoryInterface $streamFactory;
    protected CacheInterface $cache;
    protected LoggerInterface $logger;

    public function __construct(ClientInterface $httpClient,
                                RequestFactoryInterface $requestFactory,
                                StreamFactoryInterface $streamFactory,
                                CacheInterface $cache,
                                LoggerInterface $logger)
    {
        $this->httpClient = $httpClient;
        $this->requestFactory = $requestFactory;
        $this->streamFactory = $streamFactory;
        $this->cache = $cache;
        $this->logger = $logger;
    }

    public function createClient(string $username, string $password, $baseUrl = null): Client
    {
        $depotoClient = new Client($this->httpClient, $this->requestFactory, $this->streamFactory, $this->cache, $this->logger);
        $depotoClient
            ->setUsername($username)
            ->setPassword($password);

        if($baseUrl != null) {
            $depotoClient->setBaseUrl($baseUrl);
        }

        return $depotoClient;
    }
}