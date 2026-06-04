<?php

namespace Depoto;

use DateTime;
use Depoto\Exception\AuthenticationException;
use Depoto\Exception\ErrorException;
use Depoto\Exception\ServerException;
use Depoto\GraphQL\MutationBuilder;
use Depoto\GraphQL\QueryBuilder;
use Exception;
use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Log\LoggerInterface;
use Psr\SimpleCache\CacheInterface;

class Client
{
    protected string $clientId = '23_47gmzz2fhsw08gs0o480gks0o8c484kgw4sw0k00s0scsgs0cg';
    protected string $clientSecret = '3jwvev86i30g4w0kckc4ss4gokc48sko4s884wsk0g44wcsg0w';
    protected string $baseUrl = 'https://server1.depoto.cz.tomatomstage.cz';
    protected string $username;
    protected string $password;
    protected ?string $accessToken = null;
    protected ClientInterface $httpClient;
    protected RequestFactoryInterface $requestFactory;
    protected StreamFactoryInterface $streamFactory;
    protected CacheInterface $cache;
    protected LoggerInterface $logger;
    protected RequestInterface $lastRequest;
    protected ResponseInterface $lastResponse;

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

    public function setUsername(string $username): self
    {
        $this->username = $username;
        $this->accessToken = null;
        return $this;
    }

    public function getUsername(): string
    {
        return $this->username;
    }

    public function setPassword(string $password): self
    {
        $this->password = $password;
        return $this;
    }

    public function getPassword(): string
    {
        return $this->password;
    }

    public function setBaseUrl(string $baseUrl): self
    {
        $this->baseUrl = $baseUrl;
        return $this;
    }

    public function getBaseUrl(): string
    {
        return $this->baseUrl;
    }

    public function setClientId(string $clientId): self
    {
        $this->clientId = $clientId;
        return $this;
    }

    public function getClientId(): string
    {
        return $this->clientId;
    }

    public function setClientSecret(string $clientSecret): self
    {
        $this->clientSecret = $clientSecret;
        return $this;
    }

    public function getClientSecret(): string
    {
        return $this->clientSecret;
    }

    protected function getAccessToken(): ?string
    {
        if(!$this->accessToken) {
            $this->accessToken = $this->getOAuthData()['access_token'];
        }

        return $this->accessToken;
    }

    public function mutation(string $method, array $arguments, array $body): array
    {
        return $this->call('mutation', $method, $arguments, $body);
    }

    public function query(string $method, array $arguments, array $body): array
    {
        return $this->call('query', $method, $arguments, $body);
    }

    public function batchMutation(array $operations, bool $throwOnOperationErrors = false): array
    {
        return $this->batchCall('mutation', $operations, $throwOnOperationErrors);
    }

    public function batchQuery(array $operations, bool $throwOnOperationErrors = false): array
    {
        return $this->batchCall('query', $operations, $throwOnOperationErrors);
    }

    /**
     * @throws ClientExceptionInterface
     * @throws ErrorException
     * @throws AuthenticationException
     * @throws ServerException
     */
    public function call(string $type, string $method, array $arguments, array $body): array
    {
        if(!$this->isAuthenticated()) {
            $this->authenticate();
        }

        if(isset($body['data']) && !in_array('errors', $body)) {
            $body[] = 'errors';
        }

        $arguments = $this->encode($arguments);

        $builder = $type == "mutation" ? new MutationBuilder() : new QueryBuilder();
        $readyQuery = $builder
            ->name($method)
            ->arguments($arguments)
            ->body($body)
            ->build();

        $url = $this->getEndpointUri('/graphql');
        $body = json_encode(['query' => $readyQuery]);

        $this->lastRequest = $this->requestFactory->createRequest('POST', $url)
            ->withHeader('Content-Type', 'application/json; charset=utf-8')
            ->withHeader('Accept', 'application/json')
            ->withHeader('Accept-Encoding', '*')
            ->withHeader('Authorization', 'Bearer ' . $this->getAccessToken())
            ->withBody($this->streamFactory->createStream($body));

        $this->logger->debug('GQLRequest: '.$readyQuery, [$url]);
        $this->lastResponse = $this->httpClient->sendRequest($this->lastRequest);
        $responseBody = (string)$this->lastResponse->getBody();
        $this->logger->debug('GQLResponse: '.$responseBody, [$url]);
        $statusCode = $this->lastResponse->getStatusCode();

        if($statusCode >= 200 && $statusCode < 400) {
            $res = json_decode($responseBody, true);
            $res = $this->decode($res);
            if(isset($res['error']) || isset($res['errors']) || !empty($res['data'][$method]['errors'])) {
                $this->logger->warning('GQLError: '.$responseBody, [$url, $body]);
                throw new ErrorException($this->lastRequest, $this->lastResponse, $statusCode);
            }
            elseif(isset($res['data'][$method])) {
                return $res['data'][$method];
            }
            else {
                return $res;
            }
        }
        elseif($statusCode >= 400 && $statusCode <= 403) {
            $this->logger->warning($statusCode.': '.$responseBody, [$url, $body]);
            throw new AuthenticationException($this->lastRequest, $this->lastResponse, $statusCode);
        }
        else {
            $this->logger->error($statusCode.': '.$responseBody, [$url, $body]);
            throw new ServerException($this->lastRequest, $this->lastResponse, $statusCode);
        }
    }

    /**
     * @throws ClientExceptionInterface
     * @throws ErrorException
     * @throws AuthenticationException
     * @throws ServerException
     */
    public function batchCall(string $type, array $operations, bool $throwOnOperationErrors = false): array
    {
        if(!$this->isAuthenticated()) {
            $this->authenticate();
        }

        $readyQuery = $this->buildBatchGraphqlDocument($type, $operations);
        $url = $this->getEndpointUri('/graphql');
        $body = json_encode(['query' => $readyQuery]);

        $this->lastRequest = $this->requestFactory->createRequest('POST', $url)
            ->withHeader('Content-Type', 'application/json; charset=utf-8')
            ->withHeader('Accept', 'application/json')
            ->withHeader('Accept-Encoding', '*')
            ->withHeader('Authorization', 'Bearer ' . $this->getAccessToken())
            ->withBody($this->streamFactory->createStream($body));

        $this->logger->debug('GQLBatchRequest: '.$readyQuery, [$url]);
        $this->lastResponse = $this->httpClient->sendRequest($this->lastRequest);
        $responseBody = (string)$this->lastResponse->getBody();
        $this->logger->debug('GQLBatchResponse: '.$responseBody, [$url]);
        $statusCode = $this->lastResponse->getStatusCode();

        if($statusCode >= 200 && $statusCode < 400) {
            $res = json_decode($responseBody, true);
            $res = $this->decode($res);
            if(isset($res['error']) || isset($res['errors'])) {
                $this->logger->warning('GQLBatchError: '.$responseBody, [$url, $body]);
                throw new ErrorException($this->lastRequest, $this->lastResponse, $statusCode);
            }

            $data = $res['data'] ?? [];
            if($throwOnOperationErrors) {
                foreach($data as $alias => $operationResponse) {
                    if(!empty($operationResponse['errors'])) {
                        $this->logger->warning('GQLBatchOperationError: '.$responseBody, [$url, $body, $alias]);
                        throw new ErrorException($this->lastRequest, $this->lastResponse, $statusCode);
                    }
                }
            }

            return $data;
        }
        elseif($statusCode >= 400 && $statusCode <= 403) {
            $this->logger->warning($statusCode.': '.$responseBody, [$url, $body]);
            throw new AuthenticationException($this->lastRequest, $this->lastResponse, $statusCode);
        }
        else {
            $this->logger->error($statusCode.': '.$responseBody, [$url, $body]);
            throw new ServerException($this->lastRequest, $this->lastResponse, $statusCode);
        }
    }

    protected function buildBatchGraphqlDocument(string $type, array $operations): string
    {
        if(!in_array($type, ['query', 'mutation'])) {
            throw new \InvalidArgumentException('Batch GraphQL type must be query or mutation.');
        }

        if(empty($operations)) {
            throw new \InvalidArgumentException('At least one batch operation is required.');
        }

        $fields = [];
        foreach($operations as $alias => $operation) {
            if(!is_array($operation)) {
                throw new \InvalidArgumentException('Each batch operation must be an array.');
            }

            if(is_int($alias)) {
                $alias = $operation['alias'] ?? null;
            }

            if(!is_string($alias) || !$this->isValidGraphqlName($alias)) {
                throw new \InvalidArgumentException('Each batch operation alias must be a valid GraphQL name.');
            }

            $method = $operation['method'] ?? null;
            if(!is_string($method) || !$this->isValidGraphqlName($method)) {
                throw new \InvalidArgumentException("Batch operation '{$alias}' has invalid method name.");
            }

            $arguments = $operation['arguments'] ?? [];
            $body = $operation['body'] ?? [];
            if(!is_array($arguments) || !is_array($body)) {
                throw new \InvalidArgumentException("Batch operation '{$alias}' arguments and body must be arrays.");
            }

            if(isset($body['data']) && !in_array('errors', $body)) {
                $body[] = 'errors';
            }

            $fields[] = sprintf(
                '%s: %s%s%s',
                $alias,
                $method,
                $this->buildGraphqlArgumentsString($arguments),
                $this->buildGraphqlBodyString($body)
            );
        }

        return sprintf("%s {\n%s\n}", $type, implode("\n", $fields));
    }

    protected function buildGraphqlArgumentsString(array $arguments): string
    {
        if(empty($arguments)) {
            return '';
        }

        $encodedArguments = $this->encode($arguments);
        $argumentKeys = [];
        $enumValues = [];
        $this->collectGraphqlArgumentMetadata($encodedArguments, $argumentKeys, $enumValues);

        $args = json_encode($encodedArguments, JSON_UNESCAPED_UNICODE);
        $args = sprintf('(%s)', substr($args, 1, strlen($args) - 2));

        foreach($argumentKeys as $argumentKey) {
            $args = str_replace(sprintf('"%s":', $argumentKey), sprintf('%s:', $argumentKey), $args);
        }

        foreach($enumValues as $enumValue) {
            $args = str_replace(sprintf('"%s"', $enumValue), $enumValue, $args);
        }

        return $args;
    }

    protected function collectGraphqlArgumentMetadata(array $arguments, array &$argumentKeys, array &$enumValues): void
    {
        foreach($arguments as $argumentName => $argumentValue) {
            if(is_string($argumentName) && !is_numeric($argumentName)) {
                $argumentKeys[$argumentName] = $argumentName;
            }

            if(is_array($argumentValue)) {
                $this->collectGraphqlArgumentMetadata($argumentValue, $argumentKeys, $enumValues);
            }
            elseif(is_string($argumentValue) && $argumentValue === strtoupper($argumentValue) && strpos($argumentValue, 'ENUM_') !== false) {
                $enumValues[$argumentValue] = $argumentValue;
            }
        }
    }

    protected function buildGraphqlBodyString(array $body): string
    {
        if(empty($body)) {
            return '';
        }

        $bodyString = " {\n";
        $this->appendGraphqlBodyFields($body, $bodyString);
        $bodyString .= "}\n";

        return $bodyString;
    }

    protected function appendGraphqlBodyFields(array $fields, string &$bodyString): void
    {
        foreach($fields as $key => $field) {
            if(is_int($key)) {
                $bodyString .= $field . "\n";
            }
            elseif(is_string($key) && is_array($field)) {
                $bodyString .= $key . " {\n";
                $this->appendGraphqlBodyFields($field, $bodyString);
                $bodyString .= "}\n";
            }
        }
    }

    protected function isValidGraphqlName(string $name): bool
    {
        return preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $name) === 1;
    }

    /**
     * @throws ClientExceptionInterface
     * @throws AuthenticationException
     * @throws ServerException
     * @throws Exception
     */
    public function authenticate($grantType = 'password'): self
    {
        $vars = [
            'client_id' => $this->clientId,
            'client_secret' => $this->clientSecret,
            'grant_type' => $grantType,
        ];

        if($grantType == 'password') {
            $vars['username'] = $this->username;
            $vars['password'] = $this->password;
        }
        elseif($grantType == 'refresh_token') {
            $vars['refresh_token'] = $this->getOAuthData()['refresh_token'];
        }

        $body = http_build_query($vars);
        $url = $this->getEndpointUri('/oauth/v2/token');
        $this->lastRequest = $this->requestFactory->createRequest('POST', $url)
            ->withHeader('Accept-Encoding', '*')
            ->withHeader('Content-Type', 'application/x-www-form-urlencoded; charset=utf-8')
            ->withBody($this->streamFactory->createStream($body));

        $this->logger->debug('OAuthRequest: '.$body, [$url]);
        $this->lastResponse = $this->httpClient->sendRequest($this->lastRequest);
        $responseBody = (string)$this->lastResponse->getBody();
        $this->logger->debug('OAuthResponse: '.$responseBody, [$url]);
        $statusCode = $this->lastResponse->getStatusCode();

        if($statusCode >= 200 && $statusCode < 400) {
            $res = json_decode($responseBody, true);
            $this->setOAuthData($res);
        }
        elseif($statusCode >= 400 && $statusCode <= 403) {
            $res = json_decode($responseBody, true);
            if($grantType == 'password' && $res['error'] == 'invalid_token') { // The access token expired
                $this->authenticate('refresh_token');
            }
            else {
                $this->logger->warning($statusCode.': '.$responseBody, [$url, $body]);
                throw new AuthenticationException($this->lastRequest, $this->lastResponse, $statusCode);
            }
        }
        else {
            $this->logger->error($statusCode.': '.$responseBody, [$url, $body]);
            throw new ServerException($this->lastRequest, $this->lastResponse, $statusCode);
        }

        return $this;
    }

    protected function setOAuthData(array $data): void
    {
        $data['expires_time'] = $data['expires_in'] + time();
        $this->cache->set($this->getOAuthDataCacheKey(), $data);
    }

    protected function getOAuthData()
    {
        return $this->cache->get($this->getOAuthDataCacheKey());
    }

    protected function getOAuthDataCacheKey(): string
    {
        return 'depoto-oauth-'.md5($this->username);
    }

    public function isAuthenticated(): bool
    {
        $oauthData = $this->getOAuthData();
        if(!$oauthData) {
            return false;
        }

        if($oauthData['access_token'] && $oauthData['expires_time'] > time()-100) {
            return true;
        }

        return false;
    }

    protected function getEndpointUri(string $string): string
    {
        return $this->baseUrl.$string;
    }

    public function getLastRequest(): RequestInterface
    {
        return $this->lastRequest;
    }

    protected function getLastResponse(): ResponseInterface
    {
        return $this->lastResponse;
    }

    public static function encode($data)
    {
        if(is_array($data)) {
            foreach($data as $k => $d) {
                if(in_array($k, ['pkcs12', 'base64Data'])) {
                    continue;
                }
                if(is_bool($d)) {
                    continue;
                }
                if(is_object($d)) {
                    continue;
                }
                if(is_array($d)) {
                    $data[$k] = self::encode($d);
                }
                else {
                    $data[$k] = urlencode($d);
                }
            }
        }
        else {
            $data = urlencode($data);
        }

        return $data;
    }

    public static function decode($data)
    {
        if(is_array($data)) {
            foreach($data as $k => $d) {
                if(in_array($k, ['pkcs12', 'base64Data'])) {
                    continue;
                }
                if (is_null($d)) {
                    continue;
                }
                if(is_bool($d)) {
                    continue;
                }
                if(is_object($d)) {
                    continue;
                }
                if(is_array($d)) {
                    $data[$k] = self::decode($d);
                }
                else {
                    $data[$k] = urldecode($d);
                }
            }
        }
        else {
            $data = urldecode($data);
        }

        return $data;
    }
}
