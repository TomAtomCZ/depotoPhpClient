<?php

use Depoto\Client;
use Depoto\Exception\AuthenticationException;
use Depoto\Exception\ErrorException;
use Depoto\Exception\RateLimitException;
use Depoto\Exception\ServerException;
use Monolog\Handler\StreamHandler;
use Monolog\Logger;
use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\TestCase;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Log\AbstractLogger;
use Psr\Log\LoggerInterface;
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

    private function getBatchTestClient(RecordingHttpClient $httpClient, InMemoryCache $cache, bool $authenticated = true,
                                        string $clientClass = Client::class, ?LoggerInterface $logger = null): Client
    {
        $psr17Factory = new Psr17Factory();
        $username = 'unit@example.com';

        if($authenticated) {
            $cache->set('depoto-oauth-'.md5($username), [
                'access_token' => 'test-token',
                'refresh_token' => 'test-refresh-token',
                'expires_time' => time() + 3600,
            ]);
        }

        $depotoClient = new $clientClass($httpClient, $psr17Factory, $psr17Factory, $cache, $logger ?? new NullLogger());
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

    private function getRateLimitTestClient(RecordingHttpClient $httpClient, ?InMemoryCache $cache = null, bool $authenticated = true,
                                            ?LoggerInterface $logger = null): PauseRecordingClient
    {
        /** @var PauseRecordingClient $client */
        $client = $this->getBatchTestClient($httpClient, $cache ?? new InMemoryCache(), $authenticated, PauseRecordingClient::class, $logger);

        return $client;
    }

    private function createRateLimitResponse(?string $retryAfter, string $message = 'Too many concurrent requests.'): ResponseInterface
    {
        $response = $this->createJsonResponse(429, ['errors' => [['message' => $message, 'code' => 'RATE_LIMITED']]]);

        return $retryAfter === null ? $response : $response->withHeader('Retry-After', $retryAfter);
    }

    private function createProductsResponse(): ResponseInterface
    {
        return $this->createJsonResponse(200, ['data' => ['products' => ['items' => [['id' => '1']]]]]);
    }

    public function testRateLimitConfigurationDefaultsAndSetters(): void
    {
        $client = $this->getBatchTestClient(new RecordingHttpClient([]), new InMemoryCache());

        $this->assertSame(2, $client->getRateLimitRetries());
        $this->assertSame(5, $client->getMaxRateLimitWait());

        $this->assertSame($client, $client->setRateLimitRetries(4)->setMaxRateLimitWait(30));
        $this->assertSame(4, $client->getRateLimitRetries());
        $this->assertSame(30, $client->getMaxRateLimitWait());

        $client->setRateLimitRetries(-3);
        $this->assertSame(0, $client->getRateLimitRetries());
    }

    public function testRateLimitedQueryIsRetriedAfterRetryAfter(): void
    {
        $logger = new RecordingLogger();
        $httpClient = new RecordingHttpClient([
            $this->createRateLimitResponse('3'),
            $this->createProductsResponse(),
        ]);
        $client = $this->getRateLimitTestClient($httpClient, null, true, $logger);

        $result = $client->query('products', ['filters' => ['fulltext' => 'secret search']], ['items' => ['id']]);

        $this->assertSame([['id' => '1']], $result['items']);
        $this->assertSame([3], $client->pauses);
        $this->assertCount(2, $httpClient->requests);
        $this->assertSame('Bearer test-token', $httpClient->requests[0]->getHeaderLine('Authorization'));
        $this->assertSame('Bearer test-token', $httpClient->requests[1]->getHeaderLine('Authorization'));
        $this->assertNotSame('', (string)$httpClient->requests[1]->getBody());
        $this->assertSame((string)$httpClient->requests[0]->getBody(), (string)$httpClient->requests[1]->getBody());

        $retryLogs = $logger->recordsWithLevel('info');
        $this->assertCount(1, $retryLogs);
        $this->assertSame(429, $retryLogs[0]['context']['status']);
        $this->assertSame(3, $retryLogs[0]['context']['wait']);
        $this->assertSame(1, $retryLogs[0]['context']['attempt']);
        $this->assertSame('https://example.test/graphql', $retryLogs[0]['context']['url']);
        $serializedLog = json_encode($retryLogs);
        $this->assertStringNotContainsString('secret', $serializedLog);
        $this->assertStringNotContainsString('test-token', $serializedLog);
    }

    public function testRateLimitRetriesExhaustedThrowsRateLimitException(): void
    {
        $logger = new RecordingLogger();
        $httpClient = new RecordingHttpClient([
            $this->createRateLimitResponse('1'),
            $this->createRateLimitResponse('1'),
            $this->createRateLimitResponse('1'),
        ]);
        $client = $this->getRateLimitTestClient($httpClient, null, true, $logger);

        try {
            $client->query('products', [], ['items' => ['id']]);
            $this->fail('RateLimitException was not thrown.');
        }
        catch(RateLimitException $e) {
            $this->assertInstanceOf(ServerException::class, $e);
            $this->assertSame(429, $e->getCode());
            $this->assertSame(1, $e->getRetryAfter());
            $this->assertSame(429, $e->getResponse()->getStatusCode());
            $this->assertSame('https://example.test/graphql', (string)$e->getRequest()->getUri());
            $this->assertStringContainsString('Too many concurrent requests.', $e->getMessage());
            $this->assertSame([1, 1], $client->pauses);
            $this->assertCount(3, $httpClient->requests);
            $this->assertCount(1, $logger->recordsWithLevel('warning'));
            $this->assertCount(0, $logger->recordsWithLevel('error'));
        }
    }

    public function testRetryAfterAboveMaxWaitThrowsImmediately(): void
    {
        $httpClient = new RecordingHttpClient([
            $this->createRateLimitResponse('120', 'API time budget exceeded.'),
        ]);
        $client = $this->getRateLimitTestClient($httpClient);

        try {
            $client->query('products', [], ['items' => ['id']]);
            $this->fail('RateLimitException was not thrown.');
        }
        catch(RateLimitException $e) {
            $this->assertSame(120, $e->getRetryAfter());
            $this->assertStringContainsString('API time budget exceeded.', $e->getMessage());
            $this->assertSame([], $client->pauses);
            $this->assertCount(1, $httpClient->requests);
        }
    }

    public function testRetryAfterUpToRaisedMaxWaitIsWaited(): void
    {
        $httpClient = new RecordingHttpClient([
            $this->createRateLimitResponse('120'),
            $this->createProductsResponse(),
        ]);
        $client = $this->getRateLimitTestClient($httpClient);
        $client->setMaxRateLimitWait(120);

        $client->query('products', [], ['items' => ['id']]);

        $this->assertSame([120], $client->pauses);
        $this->assertCount(2, $httpClient->requests);
    }

    public function testRateLimitRetriesCanBeDisabled(): void
    {
        $httpClient = new RecordingHttpClient([
            $this->createRateLimitResponse('1'),
        ]);
        $client = $this->getRateLimitTestClient($httpClient);
        $client->setRateLimitRetries(0);

        try {
            $client->query('products', [], ['items' => ['id']]);
            $this->fail('RateLimitException was not thrown.');
        }
        catch(RateLimitException $e) {
            $this->assertSame(1, $e->getRetryAfter());
            $this->assertSame([], $client->pauses);
            $this->assertCount(1, $httpClient->requests);
        }
    }

    public function testMissingRetryAfterWaitsOneSecond(): void
    {
        $httpClient = new RecordingHttpClient([
            $this->createRateLimitResponse(null),
            $this->createRateLimitResponse('0'),
            $this->createRateLimitResponse('soon'),
        ]);
        $client = $this->getRateLimitTestClient($httpClient);

        try {
            $client->query('products', [], ['items' => ['id']]);
            $this->fail('RateLimitException was not thrown.');
        }
        catch(RateLimitException $e) {
            $this->assertNull($e->getRetryAfter());
            $this->assertSame([1, 1], $client->pauses);
            $this->assertCount(3, $httpClient->requests);
        }
    }

    public function testHttpDateRetryAfterIsWaitedUntilThatDate(): void
    {
        $httpClient = new RecordingHttpClient([
            $this->createRateLimitResponse(gmdate('D, d M Y H:i:s \G\M\T', time() + 3)),
            $this->createProductsResponse(),
        ]);
        $client = $this->getRateLimitTestClient($httpClient);

        $client->query('products', [], ['items' => ['id']]);

        $this->assertCount(1, $client->pauses);
        // The second may tick between creating the header and parsing it
        $this->assertGreaterThanOrEqual(2, $client->pauses[0]);
        $this->assertLessThanOrEqual(3, $client->pauses[0]);
        $this->assertCount(2, $httpClient->requests);
    }

    public function testRetryAfterHeaderParsing(): void
    {
        $now = gmmktime(7, 28, 0, 10, 21, 2015); // Wed, 21 Oct 2015 07:28:00 GMT
        $psr17Factory = new Psr17Factory();
        $parse = function(?string $retryAfter) use ($psr17Factory, $now): ?int {
            $response = $psr17Factory->createResponse(429);
            if($retryAfter !== null) {
                $response = $response->withHeader('Retry-After', $retryAfter);
            }
            return RateLimitException::parseRetryAfter($response, $now);
        };

        $this->assertSame(0, $parse('0'));
        $this->assertSame(120, $parse('120'));
        $this->assertSame(7, $parse(' 7 '));
        $this->assertSame(90, $parse('Wed, 21 Oct 2015 07:29:30 GMT'));
        $this->assertSame(90, $parse('Wednesday, 21-Oct-15 07:29:30 GMT'));
        $this->assertSame(90, $parse('Wed Oct 21 07:29:30 2015'));
        $this->assertSame(0, $parse('Wed, 21 Oct 2015 07:00:00 GMT'));
        $this->assertSame(11 * 86400, $parse('Sun Nov  1 07:28:00 2015')); // asctime pads a 1-digit day with a space
        $this->assertSame(2147483647, $parse('99999999999999999999'));
        $this->assertNull($parse('Mon, 21 Oct 2015 07:29:30 GMT')); // weekday does not match the date
        $this->assertNull($parse('Monday, 21-Oct-15 07:29:30 GMT'));
        $this->assertNull($parse('Mon Oct 21 07:29:30 2015'));
        $this->assertNull($parse(null));
        $this->assertNull($parse(''));
        $this->assertNull($parse('-5'));
        $this->assertNull($parse('1.5'));
        $this->assertNull($parse('soon'));
        $this->assertNull($parse('tomorrow'));
        $this->assertNull($parse('Wed, 32 Oct 2015 07:29:30 GMT'));
    }

    public function testRateLimitedMutationIsRetried(): void
    {
        $httpClient = new RecordingHttpClient([
            $this->createRateLimitResponse('1'),
            $this->createJsonResponse(200, ['data' => ['createProduct' => ['data' => ['id' => '5']]]]),
        ]);
        $client = $this->getRateLimitTestClient($httpClient);

        $result = $client->mutation('createProduct', ['name' => 'Product'], ['data' => ['id']]);

        $this->assertSame('5', $result['data']['id']);
        $this->assertSame([1], $client->pauses);
        $this->assertCount(2, $httpClient->requests);
        $this->assertStringContainsString('mutation', (string)$httpClient->requests[1]->getBody());
        $this->assertSame((string)$httpClient->requests[0]->getBody(), (string)$httpClient->requests[1]->getBody());
    }

    public function testRateLimitedBatchCallIsRetried(): void
    {
        $httpClient = new RecordingHttpClient([
            $this->createRateLimitResponse('2'),
            $this->createJsonResponse(200, ['data' => ['first' => ['data' => ['id' => '5']]]]),
        ]);
        $client = $this->getRateLimitTestClient($httpClient);

        $result = $client->batchMutation([
            'first' => ['method' => 'createProduct', 'arguments' => ['name' => 'Product'], 'body' => ['data' => ['id']]],
        ]);

        $this->assertSame('5', $result['first']['data']['id']);
        $this->assertSame([2], $client->pauses);
        $this->assertCount(2, $httpClient->requests);
        $this->assertSame((string)$httpClient->requests[0]->getBody(), (string)$httpClient->requests[1]->getBody());
    }

    public function testRateLimitedBatchCallThrowsRateLimitException(): void
    {
        $httpClient = new RecordingHttpClient([
            $this->createRateLimitResponse('60'),
        ]);
        $client = $this->getRateLimitTestClient($httpClient);

        try {
            $client->batchQuery(['productList' => ['method' => 'products', 'body' => ['items' => ['id']]]]);
            $this->fail('RateLimitException was not thrown.');
        }
        catch(RateLimitException $e) {
            $this->assertSame(60, $e->getRetryAfter());
            $this->assertSame([], $client->pauses);
            $this->assertCount(1, $httpClient->requests);
        }
    }

    public function testRejectedTokenFollowedByRateLimitIsRetriedWithNewToken(): void
    {
        $httpClient = new RecordingHttpClient([
            $this->createJsonResponse(401, ['message' => 'An authentication exception occurred.']),
            $this->createJsonResponse(200, ['access_token' => 'new-token', 'refresh_token' => 'new-refresh-token', 'expires_in' => 43200]),
            $this->createRateLimitResponse('2'),
            $this->createProductsResponse(),
        ]);
        $client = $this->getRateLimitTestClient($httpClient);

        $result = $client->query('products', [], ['items' => ['id']]);

        $this->assertSame([['id' => '1']], $result['items']);
        $this->assertSame([2], $client->pauses);
        $this->assertCount(4, $httpClient->requests);
        $this->assertSame('https://example.test/oauth/v2/token', (string)$httpClient->requests[1]->getUri());
        $this->assertSame('Bearer new-token', $httpClient->requests[2]->getHeaderLine('Authorization'));
        $this->assertSame('Bearer new-token', $httpClient->requests[3]->getHeaderLine('Authorization'));
        $this->assertSame((string)$httpClient->requests[0]->getBody(), (string)$httpClient->requests[3]->getBody());
    }

    public function testRateLimitRetriesAreBoundedAcrossTokenRenewal(): void
    {
        $httpClient = new RecordingHttpClient([
            $this->createRateLimitResponse('1'),
            $this->createJsonResponse(401, ['error' => 'invalid_grant']),
            $this->createJsonResponse(200, ['access_token' => 'new-token', 'refresh_token' => 'new-refresh-token', 'expires_in' => 43200]),
            $this->createRateLimitResponse('1'),
            $this->createRateLimitResponse('1'),
        ]);
        $client = $this->getRateLimitTestClient($httpClient);

        try {
            $client->query('products', [], ['items' => ['id']]);
            $this->fail('RateLimitException was not thrown.');
        }
        catch(RateLimitException $e) {
            $this->assertSame([1, 1], $client->pauses);
            $this->assertCount(5, $httpClient->requests);
            $this->assertSame('Bearer test-token', $httpClient->requests[1]->getHeaderLine('Authorization'));
            $this->assertSame('Bearer new-token', $httpClient->requests[4]->getHeaderLine('Authorization'));
        }
    }

    public function testRetriedRequestUsesTokenRenewedInCacheMeanwhile(): void
    {
        $cache = new InMemoryCache();
        $httpClient = new RecordingHttpClient([
            function() use ($cache, &$client) {
                $this->setCachedAccessToken($cache, $client, 'other-client-token');
                return $this->createRateLimitResponse('1');
            },
            $this->createProductsResponse(),
        ]);
        $client = $this->getRateLimitTestClient($httpClient, $cache);

        $client->query('products', [], ['items' => ['id']]);

        $this->assertCount(2, $httpClient->requests);
        $this->assertSame('Bearer test-token', $httpClient->requests[0]->getHeaderLine('Authorization'));
        $this->assertSame('Bearer other-client-token', $httpClient->requests[1]->getHeaderLine('Authorization'));
    }

    public function testRateLimitedTokenRequestThrowsRateLimitException(): void
    {
        $httpClient = new RecordingHttpClient([
            $this->createRateLimitResponse('1'),
        ]);
        $client = $this->getRateLimitTestClient($httpClient, null, false);

        try {
            $client->authenticate();
            $this->fail('RateLimitException was not thrown.');
        }
        catch(RateLimitException $e) {
            $this->assertSame(429, $e->getCode());
            $this->assertSame(1, $e->getRetryAfter());
            $this->assertSame('https://example.test/oauth/v2/token', (string)$e->getRequest()->getUri());
            $this->assertSame([], $client->pauses);
            $this->assertCount(1, $httpClient->requests);
        }
    }

    public function testRateLimitedTokenRequestBeforeQueryThrowsRateLimitException(): void
    {
        $httpClient = new RecordingHttpClient([
            $this->createRateLimitResponse('1'),
        ]);
        $client = $this->getRateLimitTestClient($httpClient, null, false);

        $this->expectException(RateLimitException::class);

        $client->query('products', [], ['items' => ['id']]);
    }

    public function testRateLimitedReauthenticationThrowsRateLimitException(): void
    {
        $httpClient = new RecordingHttpClient([
            $this->createJsonResponse(401, ['error' => 'invalid_grant']),
            $this->createRateLimitResponse('30'),
        ]);
        $client = $this->getRateLimitTestClient($httpClient);

        try {
            $client->query('products', [], ['items' => ['id']]);
            $this->fail('RateLimitException was not thrown.');
        }
        catch(RateLimitException $e) {
            $this->assertSame(30, $e->getRetryAfter());
            $this->assertSame('https://example.test/oauth/v2/token', (string)$e->getRequest()->getUri());
            $this->assertSame([], $client->pauses);
            $this->assertCount(2, $httpClient->requests);
        }
    }

    public function testServerErrorIsNotRetried(): void
    {
        $logger = new RecordingLogger();
        $httpClient = new RecordingHttpClient([
            $this->createJsonResponse(503, ['message' => 'Service unavailable'])->withHeader('Retry-After', '1'),
        ]);
        $client = $this->getRateLimitTestClient($httpClient, null, true, $logger);

        try {
            $client->query('products', [], ['items' => ['id']]);
            $this->fail('ServerException was not thrown.');
        }
        catch(ServerException $e) {
            $this->assertNotInstanceOf(RateLimitException::class, $e);
            $this->assertSame(503, $e->getCode());
            $this->assertSame([], $client->pauses);
            $this->assertCount(1, $httpClient->requests);
            $this->assertCount(1, $logger->recordsWithLevel('error'));
        }
    }

    public function testClientErrorIsNotRetried(): void
    {
        $httpClient = new RecordingHttpClient([
            $this->createJsonResponse(400, ['message' => 'Bad request']),
        ]);
        $client = $this->getRateLimitTestClient($httpClient);

        try {
            $client->mutation('createProduct', ['name' => 'Product'], ['data' => ['id']]);
            $this->fail('AuthenticationException was not thrown.');
        }
        catch(AuthenticationException $e) {
            $this->assertSame(400, $e->getCode());
            $this->assertSame([], $client->pauses);
            $this->assertCount(1, $httpClient->requests);
        }
    }

    public function errorStatusProvider(): array
    {
        $cases = [];
        foreach(['call', 'batchCall'] as $method) {
            $cases[$method.', 400'] = [$method, 400, AuthenticationException::class, 'warning'];
            $cases[$method.', 403'] = [$method, 403, AuthenticationException::class, 'warning'];
            $cases[$method.', 429'] = [$method, 429, RateLimitException::class, 'warning'];
            $cases[$method.', 404'] = [$method, 404, ServerException::class, 'error'];
            $cases[$method.', 500'] = [$method, 500, ServerException::class, 'error'];
        }

        return $cases;
    }

    /**
     * @dataProvider errorStatusProvider
     */
    public function testErrorStatusIsThrownAndLogged(string $method, int $statusCode, string $exceptionClass, string $level): void
    {
        $logger = new RecordingLogger();
        $responseBody = json_encode(['errors' => [['message' => 'Refused.', 'code' => 'RATE_LIMITED']]]);
        $psr17Factory = new Psr17Factory();
        $httpClient = new RecordingHttpClient([
            $psr17Factory->createResponse($statusCode)->withBody($psr17Factory->createStream($responseBody)),
        ]);
        $client = $this->getRateLimitTestClient($httpClient, null, true, $logger);
        $client->setRateLimitRetries(0);

        try {
            if($method == 'call') {
                $client->query('products', [], ['items' => ['id']]);
            }
            else {
                $client->batchQuery(['productList' => ['method' => 'products', 'body' => ['items' => ['id']]]]);
            }
            $this->fail($exceptionClass.' was not thrown.');
        }
        catch(ServerException|AuthenticationException $e) {
            $this->assertSame($exceptionClass, get_class($e));
            $this->assertSame($statusCode, $e->getCode());
        }

        $records = array_values(array_filter($logger->records, function(array $record) {
            return $record['level'] !== 'debug';
        }));
        $this->assertCount(1, $records);
        $this->assertSame($level, $records[0]['level']);
        $this->assertSame($statusCode.': '.$responseBody, $records[0]['message']);
        $this->assertSame(['https://example.test/graphql', (string)$httpClient->requests[0]->getBody()], $records[0]['context']);
    }

    public function tokenResponseProvider(): array
    {
        return [
            'password grant, 200' => ['password', 200],
            'password grant, 400' => ['password', 400],
            'password grant, 500' => ['password', 500],
            'refresh_token grant, 200' => ['refresh_token', 200],
            'refresh_token grant, 400' => ['refresh_token', 400],
            'refresh_token grant, 500' => ['refresh_token', 500],
        ];
    }

    /**
     * @dataProvider tokenResponseProvider
     */
    public function testTokenEndpointLogsContainNoCredentials(string $grantType, int $statusCode): void
    {
        $cache = new InMemoryCache();
        $logger = new RecordingLogger();
        $responseData = [
            200 => ['access_token' => 'issued-access-token', 'refresh_token' => 'issued-refresh-token', 'expires_in' => 43200],
            400 => ['error' => 'invalid_grant', 'error_description' => 'Invalid username and password combination'],
            500 => ['message' => 'Internal server error'],
        ];
        $httpClient = new RecordingHttpClient([$this->createJsonResponse($statusCode, $responseData[$statusCode])]);
        $client = $this->getBatchTestClient($httpClient, $cache, false, Client::class, $logger);
        $client
            ->setClientSecret('client-secret-value')
            ->setPassword('password-value');
        $cache->set('depoto-oauth-'.md5($client->getUsername()), [
            'access_token' => 'old-access-token',
            'refresh_token' => 'stored-refresh-token',
            'expires_time' => time() - 10,
        ]);

        try {
            $client->authenticate($grantType);
            $this->assertSame(200, $statusCode);
        }
        catch(ServerException|AuthenticationException $e) {
            $this->assertNotSame(200, $statusCode);
        }

        $this->assertCount(1, $httpClient->requests);
        $requestBody = (string)$httpClient->requests[0]->getBody();
        $this->assertStringContainsString('client_secret=client-secret-value', $requestBody);

        $this->assertNotEmpty($logger->records);
        $serializedLog = json_encode($logger->records);
        foreach(['client-secret-value', 'password-value', 'stored-refresh-token', 'issued-access-token', 'issued-refresh-token'] as $secret) {
            $this->assertStringNotContainsString($secret, $serializedLog);
        }
        $this->assertStringContainsString('client_secret=***', $serializedLog);
        $this->assertStringContainsString('grant_type='.$grantType, $serializedLog);
        $this->assertStringContainsString('example.test\/oauth\/v2\/token', $serializedLog);
    }

    public function testGraphqlErrorIsNotRetried(): void
    {
        $httpClient = new RecordingHttpClient([
            $this->createJsonResponse(200, ['errors' => [['message' => 'Too many concurrent requests.']]]),
        ]);
        $client = $this->getRateLimitTestClient($httpClient);

        try {
            $client->query('products', [], ['items' => ['id']]);
            $this->fail('ErrorException was not thrown.');
        }
        catch(ErrorException $e) {
            $this->assertSame(200, $e->getCode());
            $this->assertSame([], $client->pauses);
            $this->assertCount(1, $httpClient->requests);
        }
    }
}

class PauseRecordingClient extends Client
{
    /** @var int[] */
    public array $pauses = [];

    protected function pause(int $seconds): void
    {
        $this->pauses[] = $seconds;
    }
}

class RecordingLogger extends AbstractLogger
{
    public array $records = [];

    /**
     * Untyped $message and the void return type keep the declaration compatible with psr/log 1, 2 and 3.
     */
    public function log($level, $message, array $context = []): void
    {
        $this->records[] = ['level' => $level, 'message' => $message, 'context' => $context];
    }

    public function recordsWithLevel(string $level): array
    {
        return array_values(array_filter($this->records, function(array $record) use ($level) {
            return $record['level'] === $level;
        }));
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
