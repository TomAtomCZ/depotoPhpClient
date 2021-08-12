<?php

use Depoto\Client;
use Depoto\Exception\AuthenticationException;
use Monolog\Handler\StreamHandler;
use Monolog\Logger;
use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ApcuAdapter;
use Symfony\Component\Cache\Psr16Cache;
use Symfony\Component\HttpClient\Psr18Client;

class ClientTest extends TestCase
{
    private function getDepotoClient(): Client
    {
        $httpClient = new Psr18Client();
        $psr17Factory = new Psr17Factory();
        $cache = new Psr16Cache(new ApcuAdapter('Depoto'));
        $logger = new Logger('Depoto', [new StreamHandler('depoto.log', Logger::DEBUG)]);

        $depotoClient = new Client($httpClient, $psr17Factory, $psr17Factory, $cache, $logger);
        $depotoClient
            ->setBaseUrl('https://server-dev.depoto.cz/app_dev.php')
            ->setUsername('test@depoto.cz')
            ->setPassword('besttest');

        return $depotoClient;
    }

    public function testFailedAuthentication(): void
    {
        $this->expectException(AuthenticationException::class);

        $depotoClient = $this->getDepotoClient();
        $depotoClient
            ->setUsername('failed@example.cz')
            ->setPassword('failed test')
            ->authenticate();
    }

    public function testSuccessAuthentication(): void
    {
        $depotoClient = $this->getDepotoClient();
        $depotoClient->authenticate();

        $this->assertTrue($depotoClient->isAuthenticated());
    }

    public function testRefreshTokenAuthentication(): void
    {
        $depotoClient = $this->getDepotoClient();
        $depotoClient->authenticate()->authenticate('refresh_token');

        $this->assertTrue($depotoClient->isAuthenticated());
    }

    public function testQueryProducts(): void
    {
        $products = $this->getDepotoClient()->query('products',
            ['filters' => ['fulltext' => '']],
            ['items' => ['id', 'name']]);

        $this->assertIsArray($products);
    }

    public function testMutationCreateProduct(): void
    {
        $name = 'Test+ěščřžýáíé=';
        $product = $this->getDepotoClient()->mutation('createProduct',
            ['name' => $name],
            ['data' => ['id', 'name']]);

        $this->assertArrayHasKey('data', $product);
        $this->assertArrayHasKey('id', $product['data']);
        $this->assertArrayHasKey('name', $product['data']);
        $this->assertEquals($name, $product['data']['name']);
    }
}