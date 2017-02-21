<?php

namespace Depoto;

use Exception;
use QueryBuilder\Mutation\MutationBuilder;
use QueryBuilder\Query\QueryBuilder;
use function GuzzleHttp\json_decode;
use function GuzzleHttp\json_encode;

class Client
{
    protected $guzzle;
    protected $accessToken;
    protected $baseUrl;
    protected $username;
    protected $password;
    protected $clientId;
    protected $clientSecret;

    /**
     *
     * @param string $username
     * @param string $password
     * @param string $baseUrl
     * @param string $clientId
     * @param string $clientSecret
     */
    public function __construct($username, $password, 
        $baseUrl = 'https://server1.depoto.cz.tomatomstage.cz',
        $clientId = '1_2rw1go4w8igw84g0ko488cs8c0ws4ccc8sgsc8ckgoo48ccco8',
        $clientSecret = '3lvk182vjscgcs4ws44sks88skgkowoc00ow084soc0oc0gg88'
        )
    {
        $this->username = $username;
        $this->password = $password;
        $this->baseUrl = $baseUrl;
        $this->clientId = $clientId;
        $this->clientSecret = $clientSecret;

        $this->guzzle = new \GuzzleHttp\Client();
        $this->authenticate();
    }

    public function mutation($method, $data, $query)
    {
        $builder = new MutationBuilder();
        $readyQuery = $builder
            ->name($method)
            ->arguments($data)
            ->body($query)
            ->build();

        $res = $this->guzzle->request('POST', $this->getEndpoint('/graphql'), [
            'json' => [
                'query' => $readyQuery,
            ],
            'headers' => [
                'Authorization' => 'Bearer ' . $this->accessToken,
                'Accept' => 'application/json', 
                'Content-type' => 'application/json',
            ],
            //'debug' => true
        ]);
        
        $res = json_decode((string)$res->getBody(), true);

        if(isset($res['errors'])){
            throw new Exception(json_encode($res['errors']));
        }
        elseif(isset($res['data'][$method])) {
            return $res['data'][$method];
        }
        else {
            return $res;
        }
    }
    
    public function query($method, $data, $query)
    {
        $builder = new QueryBuilder();
        $readyQuery = $builder
            ->name($method)
            ->arguments($data)
            ->body($query)
            ->build();

        $res = $this->guzzle->request('POST', $this->getEndpoint('/graphql'), [
            'json' => [
                'query' => $readyQuery,
            ],
            'headers' => [
                'Authorization' => 'Bearer ' . $this->accessToken,
                'Accept' => 'application/json', 
                'Content-type' => 'application/json',
            ],
            //'debug' => true
        ]);
        
        $res = json_decode((string)$res->getBody(), true);

        if(isset($res['errors'])){
            throw new Exception(json_encode($res['errors']));
        }
        elseif(isset($res['data'][$method])) {
            return $res['data'][$method];
        }
        else {
            return $res;
        }
    }

    protected function getEndpoint($str)
    {
        return $this->baseUrl.$str;
    }

    protected function authenticate()
    {
        $res = $this->guzzle->request('GET', $this->getEndpoint('/oauth/v2/token'), [
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
