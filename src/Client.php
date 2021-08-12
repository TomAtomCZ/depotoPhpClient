<?php

namespace Depoto;

use Depoto\GraphQL\MutationBuilder;
use Depoto\GraphQL\QueryBuilder;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Log\LoggerInterface;

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

    public function mutation($method, $data, $query)
    {
        $builder = new MutationBuilder();
        $readyQuery = $builder
            ->name($method)
            ->arguments($data)
            ->body($query)
            ->build();


        $request = $this->requestFactory->createRequest('POST', $this->getEndpointUri('/graphql'));
        $request->withHeader()
                ->withBody('xa');

        $response = $this->httpClient->sendRequest($request);

//        request('POST', $this->getEndpoint('/graphql'), [
//            'json' => [
//                'query' => $readyQuery,
//            ],
//            'headers' => [
//                'Authorization' => 'Bearer ' . $this->accessToken,
//                'Accept' => 'application/json',
//                'Content-type' => 'application/json; charset=utf-8',
//            ],
//            //'debug' => true
//        ]);
//
//        $res = json_decode((string)$res->getBody(), true);
//
//        if(isset($res['errors'])){
//            throw new Exception(json_encode($res['errors']));
//        }
//        elseif(isset($res['data'][$method])) {
//            return $res['data'][$method];
//        }
//        else {
//            return $res;
//        }
    }
    
    public function query($method, $data, $query)
    {
//        $builder = new QueryBuilder();
//        $readyQuery = $builder
//            ->name($method)
//            ->arguments($data)
//            ->body($query)
//            ->build();
//
//        $res = $this->guzzle->request('POST', $this->getEndpoint('/graphql'), [
//            'json' => [
//                'query' => $readyQuery,
//            ],
//            'headers' => [
//                'Authorization' => 'Bearer ' . $this->accessToken,
//                'Accept' => 'application/json',
//                'Content-type' => 'application/json; charset=utf-8',
//            ],
//            //'debug' => true
//        ]);
//
//        $res = json_decode((string)$res->getBody(), true);
//
//        if(isset($res['errors'])){
//            throw new Exception(json_encode($res['errors']));
//        }
//        elseif(isset($res['data'][$method])) {
//            return $res['data'][$method];
//        }
//        else {
//            return $res;
//        }
    }

    protected function getEndpointUri(string $string): string
    {
        return $this->baseUrl.$string;
    }

    public function authenticate()
    {
        $request = $this->requestFactory->createRequest('POST', $this->getEndpointUri('/oauth/v2/token'));
        $body = http_build_query([
            'client_id' => $this->clientId,
            'client_secret' => $this->clientSecret,
            'grant_type' => 'password',
            'username' => $this->username,
            'password' => $this->password,
        ]);
        var_dump($body);
        $request
            ->withHeader('Content-Type', 'application/x-www-form-urlencoded')
            ->withBody($this->streamFactory->createStream($body));
        var_dump($request);
        $response = $this->httpClient->sendRequest($request);
        var_dump((string)$response->getBody());
        die();

        $res = $this->guzzle->request('GET', $this->getEndpointUri('/oauth/v2/token'), [
            'query' => [
                'client_id' => $this->clientId,
                'client_secret' => $this->clientSecret,
                'grant_type' => 'password',
                'username' => $this->username,
                'password' => $this->password,
            ],
            'headers' => []
        ]);

        $res = json_decode((string)$res->getBody(), true);
        $this->accessToken = $res['access_token'];
    }
    
    /**
     * 
     * @param int $checkoutId
     * @param int $number
     * @param string $dateCreated
     * @param string $currency
     * @param int $totalPrice
     * @param int $priceZeroVat
     * @param int $priceStandardVat
     * @param int $vatStandard
     * @param int $priceFirstReducedVat
     * @param int $vatFirstReduced
     * @param int $priceSecondReducedVat
     * @param int $vatSecondReduced
     * @param int $priceForSubsequentSettlement
     * @param int $priceUsedSubsequentSettlement
     * @return array
     * @throws Exception
     */
    public function createEetReceipt($checkoutId, $number, $dateCreated, $currency, $totalPrice,
            $priceZeroVat = null, $priceStandardVat = null, $vatStandard = null, $priceFirstReducedVat = null,
            $vatFirstReduced = null, $priceSecondReducedVat = null, $vatSecondReduced = null, 
            $priceForSubsequentSettlement = null, $priceUsedSubsequentSettlement = null
            ) 
    {
        $data = [
                    'checkout' => $checkoutId,
                    'number' => $number,
                    'dateCreated' => $dateCreated,
                    'currency' => $currency,
                    'totalPrice' => $totalPrice,
                    'priceZeroVat' => $priceZeroVat,
                    'priceStandardVat' => $priceStandardVat,
                    'vatStandard' => $vatStandard,
                    'priceFirstReducedVat' => $priceFirstReducedVat,
                    'vatFirstReduced' => $vatFirstReduced,
                    'priceSecondReducedVat' => $priceSecondReducedVat,
                    'vatSecondReduced' => $vatSecondReduced,
                    'priceForSubsequentSettlement' => $priceForSubsequentSettlement,
                    'priceUsedSubsequentSettlement' => $priceUsedSubsequentSettlement,
                ];
        
        foreach($data as $key => $val) {
            if($data[$key] == null) {
                unset($data[$key]);
            }
        }
        
        $res = $this->mutation('createEetReceipt', 
                $data, 
                ['data' => $this->getReceiptParams(), 'errors']);
        
        if(count($res['errors'])){
            throw new Exception(json_encode($res['errors']));
        }
        
        return $res;
    }
    
    /**
     * 
     * @param int $id
     * @return array
     * @throws Exception
     */
    public function sendEetReceipt($id) 
    {
        $res = $this->mutation('sendEetReceipt', 
                ['id' => $id], 
                ['data' => $this->getReceiptParams(), 'errors']);
        
        if(count($res['errors'])){
            throw new Exception(json_encode($res['errors']));
        }
        
        return $res;
    }
    
    /**
     * 
     * @param int $page
     * @param string $sort
     * @param string $direction asc/desc
     * @param array $filters
     * @return array
     * @throws Exception
     */
    public function getEetReceipts($page = 1, $sort = 'id', $direction = 'asc', $filters = []) 
    {
        $res = $this->query('eetReceipts', 
                ['page' => $page, 'sort' => $sort, 'direction' => $direction, 'filters' => $filters], 
                ['items' => $this->getReceiptParams()]);
        
        return $res;
    }
    
    /**
     * 
     * @return array
     */
    protected function getReceiptParams()
    {
        return [
                    'id',
                    'dic',
                    'checkoutEetId',
                    'shopEetId',
                    'playground',
                    'verificationMode',
                    'number',
                    'dateCreated',
                    'totalPrice',
                    'priceZeroVat',
                    'priceStandardVat',
                    'vatStandard',
                    'priceFirstReducedVat',
                    'vatFirstReduced',
                    'priceSecondReducedVat',
                    'vatSecondReduced',
                    'priceForSubsequentSettlement',
                    'priceUsedSubsequentSettlement',
                    'fik',
                    'bkp',
                    'pkp',
                ];
    }
}
