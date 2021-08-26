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

class Client
{
    protected string $baseUrl = 'https://server1.depoto.cz.tomatomstage.cz';
    protected string $clientId = '1_2rw1go4w8igw84g0ko488cs8c0ws4ccc8sgsc8ckgoo48ccco8';
    protected string $clientSecret = '3lvk182vjscgcs4ws44sks88skgkowoc00ow084soc0oc0gg88';
    protected string $username;
    protected string $password;
    protected string $accessToken;
    protected string $refreshToken;
    protected DateTime $accessTokenExpiresDate;
    protected ClientInterface $httpClient;
    protected RequestFactoryInterface $requestFactory;
    protected StreamFactoryInterface $streamFactory;
    protected LoggerInterface $logger;
    protected RequestInterface $lastRequest;
    protected ResponseInterface $lastResponse;

    public function __construct(ClientInterface $httpClient,
                                RequestFactoryInterface $requestFactory,
                                StreamFactoryInterface $streamFactory,
                                LoggerInterface $logger)
    {
        $this->httpClient = $httpClient;
        $this->requestFactory = $requestFactory;
        $this->streamFactory = $streamFactory;
        $this->logger = $logger;
    }

    public function setUsername(string $username): self
    {
        $this->username = $username;
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

    protected function getAccessToken(): string
    {
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
            ->withHeader('Authorization', 'Bearer ' . $this->accessToken)
            ->withBody($this->streamFactory->createStream($body));

        $this->logger->debug('GQLRequest: '.$readyQuery, [$url]);
        $this->lastResponse = $this->httpClient->sendRequest($this->lastRequest);
        $responseBody = (string)$this->lastResponse->getBody();
        $this->logger->debug('GQLResponse: '.$responseBody, [$url]);
        $statusCode = $this->lastResponse->getStatusCode();

        if($statusCode >= 200 && $statusCode < 400) {
            $res = json_decode($responseBody, true);
            $res = $this->decode($res);
            if(isset($res['error']) || isset($res['errors'])) {
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
            throw new AuthenticationException($this->lastRequest, $this->lastResponse);
        }
        else {
            throw new ServerException($this->lastRequest, $this->lastResponse);
        }
    }

    /**
     * @throws ClientExceptionInterface
     * @throws AuthenticationException
     * @throws ServerException
     * @throws Exception
     */
    public function authenticate()
    {
        $body = http_build_query([
            'client_id' => $this->clientId,
            'client_secret' => $this->clientSecret,
            'grant_type' => 'password',
            'username' => $this->username,
            'password' => $this->password,
        ]);

        $url = $this->getEndpointUri('/oauth/v2/token');
        $this->lastRequest = $this->requestFactory->createRequest('POST', $url)
            ->withHeader('Content-Type', 'application/x-www-form-urlencoded; charset=utf-8')
            ->withBody($this->streamFactory->createStream($body));

        $this->logger->debug('AuthenticateRequest: '.$body, [$url]);
        $this->lastResponse = $this->httpClient->sendRequest($this->lastRequest);
        $responseBody = (string)$this->lastResponse->getBody();
        $this->logger->debug('AuthenticateResponse: '.$responseBody, [$url]);
        $statusCode = $this->lastResponse->getStatusCode();

        if($statusCode >= 200 && $statusCode < 400) {
            $res = json_decode($responseBody, true);
            $this->accessToken = $res['access_token'];
            $this->refreshToken = $res['refresh_token'];
            $this->accessTokenExpiresDate = new DateTime('+'.$res['expires_in'].' seconds');
        }
        elseif($statusCode >= 400 && $statusCode <= 403) {
            throw new AuthenticationException($this->lastRequest, $this->lastResponse);
        }
        else {
            throw new ServerException($this->lastRequest, $this->lastResponse);
        }
    }

    public function isAuthenticated(): bool
    {
        if($this->accessTokenExpiresDate && $this->accessTokenExpiresDate > new DateTime('now')) {
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

    protected function encode($data)
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
                    $data[$k] = $this->encode($d);
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

    protected function decode($data)
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
                    $data[$k] = $this->decode($d);
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
