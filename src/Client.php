<?php

namespace Depoto;

use Depoto\Exception\ErrorException;
use Depoto\GraphQL\MutationBuilder;
use Depoto\GraphQL\QueryBuilder;
use http\Exception\InvalidArgumentException;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Log\LoggerInterface;
use TheSeer\Tokenizer\Exception;

class Client
{
    protected string $username;
    protected string $password;
    protected string $baseUrl = 'https://server1.depoto.cz.tomatomstage.cz';
    protected string $clientId = '1_2rw1go4w8igw84g0ko488cs8c0ws4ccc8sgsc8ckgoo48ccco8';
    protected string $clientSecret = '3lvk182vjscgcs4ws44sks88skgkowoc00ow084soc0oc0gg88';
    protected string $accessToken;
    protected ClientInterface $httpClient;
    protected RequestFactoryInterface $requestFactory;
    protected StreamFactoryInterface $streamFactory;
    protected LoggerInterface $logger;

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

    protected function setAccessToken(string $accessToken): self
    {
        $this->accessToken = $accessToken;
        return $this;
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

    public function call(string $type, string $method, array $arguments, array $body): array
    {
        //if(isset($body['data']) && !in_array($data, 'errors')) {
        //    $body[] = 'errors';
        //}

        $builder = $type == "mutation" ? new MutationBuilder() : new QueryBuilder();
        $readyQuery = $builder
            ->name($method)
            ->arguments($arguments)
            ->body($body)
            ->build();

        $request = $this->requestFactory->createRequest('POST', $this->getEndpointUri('/graphql'))
            ->withHeader('Content-Type', 'application/json; charset=utf-8')
            ->withHeader('Accept', 'application/json')
            ->withHeader('Authorization', 'Bearer ' . $this->accessToken)
            ->withBody($this->streamFactory->createStream($readyQuery));

        $response = $this->httpClient->sendRequest($request);

        if($response->getStatusCode() == 200) {
            $res = json_decode((string)$response->getBody(), true);
            if(isset($res['error']) || isset($res['errors'])) {
                throw new ErrorException($request, $response);
            }
            elseif(isset($res['data'][$method])) {
                return $res['data'][$method];
            }
            else {
                return $res;
            }
        }
        else {
            dump($response);
        }
    }

    public function authenticate()
    {
        $body = http_build_query([
            'client_id' => $this->clientId,
            'client_secret' => $this->clientSecret,
            'grant_type' => 'password',
            'username' => $this->username,
            'password' => $this->password,
        ]);

        $request = $this->requestFactory->createRequest('POST', $this->getEndpointUri('/oauth/v2/token'))
            ->withHeader('Content-Type', 'application/x-www-form-urlencoded; charset=utf-8')
            ->withBody($this->streamFactory->createStream($body));

        $response = $this->httpClient->sendRequest($request);

        if($response->getStatusCode() == 200) {
            $res = json_decode((string)$response->getBody(), true);
            $this->accessToken = $res['access_token'];
        }
        else {
            dump($response);
        }
    }

    protected function getEndpointUri(string $string): string
    {
        return $this->baseUrl.$string;
    }
}
