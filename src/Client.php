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
            if(isset($res['error']) || isset($res['errors']) || isset($res['data'][$method]['errors'])) {
                $this->logger->warning('GQLError: '.$responseBody, [$url, $body]);
                throw new ErrorException($this->lastRequest, $this->lastResponse);
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
            throw new AuthenticationException($this->lastRequest, $this->lastResponse);
        }
        else {
            $this->logger->error($statusCode.': '.$responseBody, [$url, $body]);
            throw new ServerException($this->lastRequest, $this->lastResponse);
        }
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
                throw new AuthenticationException($this->lastRequest, $this->lastResponse);
            }
        }
        else {
            $this->logger->error($statusCode.': '.$responseBody, [$url, $body]);
            throw new ServerException($this->lastRequest, $this->lastResponse);
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
