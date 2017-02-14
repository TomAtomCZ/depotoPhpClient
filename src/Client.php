<?php

namespace Depoto;

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

        if(isset($res['data'][$method])) {
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

        if(isset($res['data'][$method])) {
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
}
