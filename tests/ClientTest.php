<?php

namespace Depoto\Tests;

use Depoto\Client;
use PHPUnit\Framework\TestCase;

class ClientTest extends TestCase
{
    /**
     *
     * @var Client
     */
    protected $client;
    
    /**
     * {@inheritDoc}
     */
    protected function setUp()
    {
        $this->client = new Client('test@client.depoto.cz', 'password', 'http://api.depoto/app_dev.php');
    }
    
    public function testCreate()
    {
        $res = $this->client->createEetReceipt(10, 
                99, date('Y-m-d h:i:s'), 'czk', 10000, 
                0, 79, 21, 
                0, 0, 0, 0, 
                null, null);
        
        $this->assertCount(0, $res['errors']);
    }
    
    public function testList()
    {
        $res = $this->client->getEetReceipts();
        
        $this->assertGreaterThan(1, count($res['items']));
    }
    
    public function testSend()
    {
        $this->expectException(\Exception::class);
        
        $res = $this->client->sendEetReceipt(20);
    }
}