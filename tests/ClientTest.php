<?php

use Depoto\Client;
use Depoto\Exception\AuthenticationException;
use Depoto\Exception\ErrorException;
use Monolog\Handler\StreamHandler;
use Monolog\Logger;
use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\TestCase;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Log\NullLogger;
use Psr\SimpleCache\CacheInterface;
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

    private function getBatchTestClient(RecordingHttpClient $httpClient, InMemoryCache $cache): Client
    {
        $psr17Factory = new Psr17Factory();
        $username = 'unit@example.com';

        $cache->set('depoto-oauth-'.md5($username), [
            'access_token' => 'test-token',
            'refresh_token' => 'test-refresh-token',
            'expires_time' => time() + 3600,
        ]);

        $depotoClient = new Client($httpClient, $psr17Factory, $psr17Factory, $cache, new NullLogger());
        $depotoClient
            ->setBaseUrl('https://example.test')
            ->setUsername($username)
            ->setPassword('unused');

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

    public function testBatchQueryBuildsGraphqlRequestDocument(): void
    {
        $psr17Factory = new Psr17Factory();
        $httpClient = new RecordingHttpClient([
            $psr17Factory->createResponse(200)->withBody($psr17Factory->createStream(json_encode([
                'data' => [
                    'firstProduct' => ['data' => ['id' => '1', 'name' => 'Product']],
                    'productList' => ['items' => [['id' => '2', 'name' => 'Another product']]],
                ],
            ]))),
        ]);

        $result = $this->getBatchTestClient($httpClient, new InMemoryCache())->batchQuery([
            'firstProduct' => [
                'method' => 'product',
                'arguments' => [
                    'id' => 123,
                    'filters' => [
                        'type' => 'ENUM_ACTIVE',
                        'includeDeleted' => false,
                    ],
                ],
                'body' => [
                    'data' => [
                        'id',
                        'name',
                        'variants' => ['id', 'sku'],
                    ],
                ],
            ],
            'productList' => [
                'method' => 'products',
                'arguments' => [
                    'filters' => ['fulltext' => 'summer shirt'],
                ],
                'body' => [
                    'items' => ['id', 'name'],
                ],
            ],
        ]);

        $this->assertSame('Product', $result['firstProduct']['data']['name']);
        $this->assertCount(1, $httpClient->requests);

        $request = $httpClient->requests[0];
        $this->assertSame('POST', $request->getMethod());
        $this->assertSame('https://example.test/graphql', (string)$request->getUri());
        $this->assertSame('Bearer test-token', $request->getHeaderLine('Authorization'));

        $requestBody = json_decode((string)$request->getBody(), true);
        $this->assertIsArray($requestBody);
        $this->assertArrayHasKey('query', $requestBody);

        $query = $requestBody['query'];
        $this->assertStringContainsString('query {', $query);
        $this->assertStringContainsString('firstProduct: product(id:"123",filters:{type:ENUM_ACTIVE,includeDeleted:false})', $query);
        $this->assertStringContainsString('data {', $query);
        $this->assertStringContainsString('variants {', $query);
        $this->assertStringContainsString('errors', $query);
        $this->assertStringContainsString('productList: products(filters:{fulltext:"summer+shirt"})', $query);
        $this->assertStringContainsString('items {', $query);
    }

    public function testBatchMutationCanThrowOnOperationErrors(): void
    {
        $psr17Factory = new Psr17Factory();
        $httpClient = new RecordingHttpClient([
            $psr17Factory->createResponse(200)->withBody($psr17Factory->createStream(json_encode([
                'data' => [
                    'createProduct' => [
                        'data' => null,
                        'errors' => ['Name is required'],
                    ],
                ],
            ]))),
        ]);

        $this->expectException(ErrorException::class);

        $this->getBatchTestClient($httpClient, new InMemoryCache())->batchMutation([
            'createProduct' => [
                'method' => 'createProduct',
                'arguments' => ['name' => ''],
                'body' => ['data' => ['id', 'name']],
            ],
        ], true);
    }
}

class RecordingHttpClient implements ClientInterface
{
    /** @var RequestInterface[] */
    public array $requests = [];

    /** @var ResponseInterface[] */
    private array $responses;

    public function __construct(array $responses)
    {
        $this->responses = $responses;
    }

    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        $this->requests[] = $request;

        return array_shift($this->responses);
    }
}

class InMemoryCache implements CacheInterface
{
    private array $items = [];

    public function get($key, $default = null)
    {
        return $this->items[$key] ?? $default;
    }

    public function set($key, $value, $ttl = null)
    {
        $this->items[$key] = $value;

        return true;
    }

    public function delete($key)
    {
        unset($this->items[$key]);

        return true;
    }

    public function clear()
    {
        $this->items = [];

        return true;
    }

    public function getMultiple($keys, $default = null)
    {
        $values = [];
        foreach($keys as $key) {
            $values[$key] = $this->get($key, $default);
        }

        return $values;
    }

    public function setMultiple($values, $ttl = null)
    {
        foreach($values as $key => $value) {
            $this->set($key, $value, $ttl);
        }

        return true;
    }

    public function deleteMultiple($keys)
    {
        foreach($keys as $key) {
            $this->delete($key);
        }

        return true;
    }

    public function has($key)
    {
        return array_key_exists($key, $this->items);
    }
}
