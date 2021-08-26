<?php

use Depoto\Client;
use Depoto\Exception\AuthenticationException;
use Depoto\Exception\ServerException;
use PHPUnit\Framework\TestCase;
use Psr\Http\Client\ClientExceptionInterface;

class ClientTest extends TestCase
{
    public function testFailedAuthentication(): void
    {
        $this->expectException(AuthenticationException::class);

        $httpClient = new Symfony\Component\HttpClient\Psr18Client();
        $psr17Factory = new \Nyholm\Psr7\Factory\Psr17Factory();
        $logger = new Monolog\Logger('depoto');
        $logger->pushHandler(new \Monolog\Handler\StreamHandler('depoto.log', \Monolog\Logger::DEBUG));
        $depotoClient = new Client($httpClient, $psr17Factory, $psr17Factory, $logger);
        $depotoClient
            ->setBaseUrl('https://server-dev.depoto.cz/app_dev.php')
            ->setUsername('failed@example.cz')
            ->setPassword('failed test')
            ->authenticate();
    }

    private ?Client $depotoClient = null;

    private function getSuccessDepotoClient(): Client
    {
        if($this->depotoClient === null) {
            $httpClient = new Symfony\Component\HttpClient\Psr18Client();
            $psr17Factory = new \Nyholm\Psr7\Factory\Psr17Factory();
            $logger = new Monolog\Logger('depoto');
            $logger->pushHandler(new \Monolog\Handler\StreamHandler('depoto.log', \Monolog\Logger::DEBUG));
            $this->depotoClient = new Client($httpClient, $psr17Factory, $psr17Factory, $logger);
            $this->depotoClient
                ->setBaseUrl('https://server-dev.depoto.cz/app_dev.php')
                ->setUsername('test@tomatom.cz')
                ->setPassword('testbest')
                ->authenticate();
        }

        return $this->depotoClient;
    }

    public function testSuccessAuthentication(): void
    {
        $depotoClient = $this->getSuccessDepotoClient();

        $this->assertTrue($depotoClient->isAuthenticated());
    }

    public function testQueryProducts(): void
    {
        $depotoClient = $this->getSuccessDepotoClient();

        $products = $depotoClient->query('products', ['filters' => ['fulltext' => '']], ['items' => ['id', 'name']]);

        $this->assertIsArray($products);
    }

    public function testMutationCreateProduct(): void
    {
        $depotoClient = $this->getSuccessDepotoClient();

        //$name = urlencode('Test+ěščřžýáíé=');
        $name = 'Test+ěščřžýáíé=';
        $product = $depotoClient->mutation('createProduct', ['name' => $name], ['data' => ['id', 'name']]);

        $this->assertArrayHasKey('data', $product);
        $this->assertArrayHasKey('id', $product['data']);
        $this->assertArrayHasKey('name', $product['data']);
        $this->assertEquals($name, $product['data']['name']);
    }
}