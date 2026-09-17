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

    public function testTokenIsNotAuthenticatedShortlyBeforeExpiration(): void
    {
        $cache = new InMemoryCache();
        $client = $this->getBatchTestClient(new RecordingHttpClient([]), $cache);
        $this->assertTrue($client->isAuthenticated());

        $cacheKey = 'depoto-oauth-'.md5($client->getUsername());
        $cache->set($cacheKey, ['expires_time' => time() + 50] + $cache->get($cacheKey));
        $this->assertFalse($client->isAuthenticated());

        $cache->set($cacheKey, ['expires_time' => time() - 50] + $cache->get($cacheKey));
        $this->assertFalse($client->isAuthenticated());
    }

    public function testExpiringTokenIsRenewedAndUsedInSameInstance(): void
    {
        $cache = new InMemoryCache();
        $httpClient = new RecordingHttpClient([
            $this->createJsonResponse(200, ['data' => ['products' => ['items' => []]]]),
            $this->createJsonResponse(200, ['access_token' => 'new-token', 'refresh_token' => 'new-refresh-token', 'expires_in' => 43200]),
            $this->createJsonResponse(200, ['data' => ['products' => ['items' => []]]]),
        ]);
        $client = $this->getBatchTestClient($httpClient, $cache);

        $client->query('products', [], ['items' => ['id']]);

        $cacheKey = 'depoto-oauth-'.md5($client->getUsername());
        $cache->set($cacheKey, ['expires_time' => time() + 50] + $cache->get($cacheKey));

        $client->query('products', [], ['items' => ['id']]);

        $this->assertCount(3, $httpClient->requests);
        $this->assertSame('Bearer test-token', $httpClient->requests[0]->getHeaderLine('Authorization'));
        $this->assertSame('https://example.test/oauth/v2/token', (string)$httpClient->requests[1]->getUri());
        $this->assertSame('Bearer new-token', $httpClient->requests[2]->getHeaderLine('Authorization'));
    }

    public function testRejectedTokenIsRenewedAndRequestRetriedOnce(): void
    {
        $httpClient = new RecordingHttpClient([
            // Real Depoto server response to an expired or unknown token
            $this->createJsonResponse(401, ['message' => 'An authentication exception occurred.']),
            $this->createJsonResponse(200, ['access_token' => 'new-token', 'refresh_token' => 'new-refresh-token', 'expires_in' => 43200]),
            $this->createJsonResponse(200, ['data' => ['products' => ['items' => [['id' => '1']]]]]),
        ]);

        $result = $this->getBatchTestClient($httpClient, new InMemoryCache())
            ->query('products', [], ['items' => ['id']]);

        $this->assertSame([['id' => '1']], $result['items']);
        $this->assertCount(3, $httpClient->requests);
        $this->assertSame('Bearer test-token', $httpClient->requests[0]->getHeaderLine('Authorization'));
        $this->assertSame('https://example.test/oauth/v2/token', (string)$httpClient->requests[1]->getUri());
        $this->assertSame('Bearer new-token', $httpClient->requests[2]->getHeaderLine('Authorization'));
        $this->assertSame((string)$httpClient->requests[0]->getBody(), (string)$httpClient->requests[2]->getBody());
    }

    public function testBatchCallThrowsAuthenticationExceptionWhenRetriedRequestIsRejected(): void
    {
        $httpClient = new RecordingHttpClient([
            $this->createJsonResponse(401, ['error' => 'invalid_grant']),
            $this->createJsonResponse(200, ['access_token' => 'new-token', 'refresh_token' => 'new-refresh-token', 'expires_in' => 43200]),
            $this->createJsonResponse(401, ['error' => 'invalid_grant']),
        ]);

        try {
            $this->getBatchTestClient($httpClient, new InMemoryCache())->batchQuery([
                'productList' => ['method' => 'products', 'body' => ['items' => ['id']]],
            ]);
            $this->fail('AuthenticationException was not thrown.');
        }
        catch(AuthenticationException $e) {
            $this->assertSame(401, $e->getCode());
            $this->assertCount(3, $httpClient->requests);
        }
    }

    public function testMutationIsNotRetriedWhenUnauthorizedResponseIsNotTokenRejection(): void
    {
        $psr17Factory = new Psr17Factory();
        $httpClient = new RecordingHttpClient([
            $psr17Factory->createResponse(401)
                ->withHeader('WWW-Authenticate', 'Basic realm="server-dev"')
                ->withBody($psr17Factory->createStream('<html>401 Authorization Required</html>')),
        ]);

        try {
            $this->getBatchTestClient($httpClient, new InMemoryCache())
                ->mutation('createProduct', ['name' => 'Product'], ['data' => ['id']]);
            $this->fail('AuthenticationException was not thrown.');
        }
        catch(AuthenticationException $e) {
            $this->assertSame(401, $e->getCode());
            $this->assertCount(1, $httpClient->requests);
        }
    }

    public function testMutationIsNotRetriedWhenUnauthorizedJsonResponseHasOtherMessage(): void
    {
        $httpClient = new RecordingHttpClient([
            $this->createJsonResponse(401, ['message' => 'Access denied.']),
        ]);

        try {
            $this->getBatchTestClient($httpClient, new InMemoryCache())
                ->mutation('createProduct', ['name' => 'Product'], ['data' => ['id']]);
            $this->fail('AuthenticationException was not thrown.');
        }
        catch(AuthenticationException $e) {
            $this->assertSame(401, $e->getCode());
            $this->assertCount(1, $httpClient->requests);
        }
    }

    public function testTokenRejectedInWwwAuthenticateHeaderIsRenewedAndRequestRetried(): void
    {
        $psr17Factory = new Psr17Factory();
        $httpClient = new RecordingHttpClient([
            $psr17Factory->createResponse(401)
                ->withHeader('WWW-Authenticate', 'Bearer realm="Service", error="invalid_token", error_description="The access token provided has expired."'),
            $this->createJsonResponse(200, ['access_token' => 'new-token', 'refresh_token' => 'new-refresh-token', 'expires_in' => 43200]),
            $this->createJsonResponse(200, ['data' => ['products' => ['items' => []]]]),
        ]);

        $this->getBatchTestClient($httpClient, new InMemoryCache())
            ->query('products', [], ['items' => ['id']]);

        $this->assertCount(3, $httpClient->requests);
        $this->assertSame('https://example.test/oauth/v2/token', (string)$httpClient->requests[1]->getUri());
        $this->assertSame('Bearer new-token', $httpClient->requests[2]->getHeaderLine('Authorization'));
    }

    public function testFailedReauthenticationThrowsExceptionForRejectedGraphqlRequest(): void
    {
        $httpClient = new RecordingHttpClient([
            $this->createJsonResponse(401, ['error' => 'invalid_grant', 'error_description' => 'The access token provided has expired.']),
            $this->createJsonResponse(400, ['error' => 'invalid_grant', 'error_description' => 'Invalid username and password combination']),
        ]);

        try {
            $this->getBatchTestClient($httpClient, new InMemoryCache())
                ->query('products', [], ['items' => ['id']]);
            $this->fail('AuthenticationException was not thrown.');
        }
        catch(AuthenticationException $e) {
            $this->assertSame(401, $e->getCode());
            $this->assertSame('https://example.test/graphql', (string)$e->getRequest()->getUri());
            $this->assertSame(401, $e->getResponse()->getStatusCode());
            $this->assertCount(2, $httpClient->requests);
        }
    }

    public function testTokenRenewedByAnotherClientIsUsedWithoutReauthenticating(): void
    {
        $cache = new InMemoryCache();
        $httpClient = new RecordingHttpClient([
            function() use ($cache, &$client) {
                $this->setCachedAccessToken($cache, $client, 'other-client-token');
                return $this->createJsonResponse(401, ['error' => 'invalid_grant', 'error_description' => 'The access token provided has expired.']);
            },
            $this->createJsonResponse(200, ['data' => ['products' => ['items' => []]]]),
        ]);
        $client = $this->getBatchTestClient($httpClient, $cache);

        $client->query('products', [], ['items' => ['id']]);

        $this->assertCount(2, $httpClient->requests);
        $this->assertSame('Bearer test-token', $httpClient->requests[0]->getHeaderLine('Authorization'));
        $this->assertSame('Bearer other-client-token', $httpClient->requests[1]->getHeaderLine('Authorization'));
    }

    public function testExistingInstanceSendsTokenRenewedByAnotherClient(): void
    {
        $cache = new InMemoryCache();
        $httpClient = new RecordingHttpClient([
            $this->createJsonResponse(200, ['data' => ['products' => ['items' => []]]]),
            $this->createJsonResponse(200, ['data' => ['products' => ['items' => []]]]),
        ]);
        $client = $this->getBatchTestClient($httpClient, $cache);

        $client->query('products', [], ['items' => ['id']]);
        $this->setCachedAccessToken($cache, $client, 'other-client-token');
        $client->query('products', [], ['items' => ['id']]);

        $this->assertCount(2, $httpClient->requests);
        $this->assertSame('Bearer test-token', $httpClient->requests[0]->getHeaderLine('Authorization'));
        $this->assertSame('Bearer other-client-token', $httpClient->requests[1]->getHeaderLine('Authorization'));
    }

    private function setCachedAccessToken(InMemoryCache $cache, Client $client, string $accessToken): void
    {
        $cacheKey = 'depoto-oauth-'.md5($client->getUsername());
        $cache->set($cacheKey, ['access_token' => $accessToken, 'expires_time' => time() + 3600] + $cache->get($cacheKey));
    }

    private function createJsonResponse(int $statusCode, array $data): ResponseInterface
    {
        $psr17Factory = new Psr17Factory();

        return $psr17Factory->createResponse($statusCode)
            ->withBody($psr17Factory->createStream(json_encode($data)));
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

    /** @var ResponseInterface[]|callable[] Callable is invoked with the request and returns response */
    private array $responses;

    public function __construct(array $responses)
    {
        $this->responses = $responses;
    }

    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        $this->requests[] = $request;
        $response = array_shift($this->responses);

        return is_callable($response) ? $response($request) : $response;
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
