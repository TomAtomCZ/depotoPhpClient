# Depoto PHP Client

### Depoto
Depoto je skladový, expediční a pokladní systém

### Použití

```php
use Depoto\Client;
use Monolog\Handler\StreamHandler;
use Monolog\Logger;
use Nyholm\Psr7\Factory\Psr17Factory;
use Symfony\Component\Cache\Adapter\ApcuAdapter;
use Symfony\Component\Cache\Psr16Cache;
use Symfony\Component\HttpClient\Psr18Client;

$httpClient = new Psr18Client(); // PSR-18 Http client
$psr17Factory = new Psr17Factory(); // PSR-17
$cache = new Psr16Cache(new ApcuAdapter('Depoto')); // PSR-16 Simple cache
$logger = new Logger('Depoto', [new StreamHandler('depoto.log', Logger::DEBUG)]); // PSR Logger

$depoto = new Client($httpClient, $psr17Factory, $psr17Factory, $cache, $logger);
$depoto
    ->setBaseUrl('https://server1.depoto.cz')
    ->setUsername('username')
    ->setPassword('password');
```
```php
use Depoto\ClientFactory;

$depotoFactory = new ClientFactory($httpClient, $psr17Factory, $psr17Factory, $cache, $logger);
$depoto = $depotoFactory->createClient('username', 'password','https://server1.depoto.cz');
```
### GraphQl
* [GraphQL dokumentace](http://graphql.org/learn/)
* [nástroj pro zobrazení GraphQL endpointu + testování (GraphiQL / ChromeiQL)](https://chrome.google.com/webstore/detail/chromeiql/fkkiamalmpiidkljmicmjfbieiclmeij)

```php
$result = $depoto->query('queryName', 
    ['arg1' => $arg1, 'arg2' => $arg2],
    ['returnSchema' => ['field', 'object' => ['field']]]);

$result = $depoto->mutation('mutationName', 
    ['arg1' => $arg1, 'arg2' => $arg2],
    ['returnSchema' => ['field', 'object' => ['field']]]);
```

### Ouery
```php
$result = $depoto->query('product', 
    ['id' => $id],
    ['data' => ['id', 'name', 'ean', 'quantities' => ['field']]]);

$result = $depoto->query('products', 
    ['filters' => ['fulltext' => $search]],
    ['items' => ['id', 'name', 'ean', 'quantities' => ['field']]]);

$result = $depoto->query('order', 
    ['id' => $id],
    ['data' => ['id', 'name', 'ean', 'quantities' => ['field']]]);

$result = $depoto->query('orders', 
    ['filters' => ['fulltext' => $search]],
    ['items' => ['id', 'name', 'ean', 'quantities' => ['field']]]);
```

### Mutation
#### Vytvoření produktu
```php
$result = $depoto->mutation('createProduct', 
    [
        'name' => 'Testovací produkt',
    ],
    ['data' => ['id']]);
```
#### Úprava produktu
```php
$result = $depoto->mutation('updateProduct', 
    ['id' => $id],
    ['data' => ['id']]);
```
#### Vytvoření zákazníka
```php
$resultCustomer = $depoto->mutation('createCustomer', 
    ['id' => $id],
    ['data' => ['id']]);
```
#### Vytvoření adresy
```php
$resultAddress = $depoto->mutation('createAddress', 
    [
        'customer' => $resultCustomer['data']['id'],  // Nepovinné
        'branchId' => 123456, // Nepovinné, identifikátor pobočky např. výdejny pro zásilkovnu.
    ],
    ['data' => ['id']]);
```
#### Vytvoření objednávky
```php
$result = $depoto->mutation('createOrder', 
    [
        'status' => 'reservation',
        'customer' => $resultCustomer['data']['id'], // Nepovinné
        'invoiceAddress' => $resultAddress['data']['id']],
        'shippingAddress' => $resultAddress['data']['id']],
        'currency' => 'CZK',
        'carrier' => 'ppl',
        'items' => [
            ['product' => 123, 'amount' => 2],
        ],
        'paymentItems' => [
            ['payment' => 789, 'amount' => 589.5],
        ],
    ]
    ['data' => ['id']]);
```
#### Přidání položky do objednávky
```php
$result = $depoto->mutation('createOrderItem', 
    [
        'product' => 123
        'amount' => 5,
        'price' => 31.5,
        'vat' => 666,
    ],
    ['data' => ['id']]);
```
#### Úprava položky objednávky
```php    
$result = $depoto->mutation('updateOrderItem', 
    [
        'id' => $id,
        'amount' => 5,
    ],
    ['data' => ['id']]);    
```
#### Smazání položky objednávky
```php        
$result = $depoto->mutation('deleteOrderItem', 
    ['id' => $id],
    ['errors']]);    
```
#### Úprava objednávky
```php    
$result = $depoto->mutation('updateOrder', 
    ['id' => $id],
    ['data' => ['id']]);
```
#### Zrušení rezervace
```php    
$result = $depoto->mutation('deleteReservation', 
    ['id' => $id],
    ['errors']]);      
```