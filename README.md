# Depoto PHP Client

### Depoto
Depoto je skladový, expediční a pokladní systém poskytující GraphQl API s OAuth2 authentifikací.
Tato knihovna má za cíl práci s API zpříjemnit;) 

### Instalace

Doporučujeme instalaci pomocí
[Composer](https://getcomposer.org/):

```bash
composer require tomatom/depoto-php-client
```

### Použití
```php
use Depoto\Client;
use Monolog\Handler\StreamHandler;
use Monolog\Logger;
use Nyholm\Psr7\Factory\Psr17Factory;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Cache\Psr16Cache;
use Symfony\Component\HttpClient\Psr18Client;

$httpClient = new Psr18Client(); // PSR-18 Http client
$psr17Factory = new Psr17Factory(); // PSR-17 HTTP Factories,  PSR-7 HTTP message
$cache = new Psr16Cache(new ArrayAdapter()); // PSR-16 Simple cache
$logger = new Logger('Depoto', [new StreamHandler('depoto.log', Logger::DEBUG)]); // PSR-3 Logger

$depoto = new Client($httpClient, $psr17Factory, $psr17Factory, $cache, $logger);
$depoto
    ->setBaseUrl('https://server1.depoto.cz') // Pro testování: https://server1.depoto.cz.tomatomstage.cz
    ->setUsername('username')
    ->setPassword('password');
```
Pokud se z vaší aplikace potřebujete připojovat k různým účtů, možná vašemu service containeru příjde vhod továrna: 
```php
use Depoto\ClientFactory;

$depotoFactory = new ClientFactory($httpClient, $psr17Factory, $psr17Factory, $cache, $logger);
$depoto = $depotoFactory->createClient('username', 'password', 'https://server1.depoto.cz');
```
### GraphQl
 * Query pro čtení a Mutation pro czápis.
 * Definuji data (stromovou strukturu), která chci vrátit.
 * [GraphQL dokumentace](http://graphql.org/learn/)
 
```php
$result = $depoto->query('queryName', 
    ['arg1' => $arg1, 'arg2' => $arg2],
    ['returnSchema' => ['field', 'object' => ['field']]]);

$result = $depoto->mutation('mutationName', 
    ['arg1' => $arg1, 'arg2' => $arg2],
    ['returnSchema' => ['field', 'object' => ['field']]]);
```

> Pro testování queries a __procházení kompletního aktuálního schématu__ použijte [GraphQL Explorer / GraphiQL konzoli](https://server1.depoto.cz/graphql/explorer).

### Ouery
#### Detail produktu
```php
$result = $depoto->query('product', 
    ['id' => $id],
    ['data' => [
        'id', 'name', 'ean', 'code', 
        'quantities' => [
            'depot' => ['id', 'name'], // Sklad
            'quantityStock', // Množství na skladě
            'quantityReservation', // Množství v rezervaci
            'quantityAvailable', // Dostupné množství
        ],
        // a další viz GraphQL Explorer
    ]]);
```    
#### Výpis produktů
```php
$result = $depoto->query('products', 
    ['filters' => ['fulltext' => $search]], // Filtry
    ['items' => [
        'id', 'name', 'ean', 'code', 
        'quantities' => [
            'depot' => ['id', 'name'], // Sklad
            'quantityStock', // Množství na skladě
            'quantityReservation', // Množství v rezervaci
            'quantityAvailable', // Dostupné množství
        ]
    ]]);
```    

#### Detail objednávky
* [Seznam procesních stávů, kterými mohou objednávky procházet](https://github.com/TomAtomCZ/depotoPhpClient/wiki/Procesn%C3%AD-stavy-objedn%C3%A1vky)

```php
$result = $depoto->query('order', 
    ['id' => $id],
    ['data' => [
        'id', 'reservationNumber' 
        'processStatus' => ['id', 'name', 'note', 'created'], // Aktuální procesní stav,  
        'items' => ['name', 'amount'],
        'paymentItems' => ['name', 'amount', 'currency'],
        'carrier' => ['id', 'name'],
        'externalId'
    ]]);
```    
#### Výpis objednávek
```php
$result = $depoto->query('orders', 
    ['filters' => ['fulltext' => $search]],
    ['items' => [
        'id', 'reservationNumber' 
        'processStatus' => ['id', 'name', 'note', 'created'],   
        'items' => ['name', 'amount'],
        'paymentItems' => ['name', 'amount', 'currency'],
        'carrier' => ['id', 'name'],
        'externalId'
    ]]);
```

### Mutation
#### Vytvoření produktu
```php
$result = $depoto->mutation('createProduct', 
    [
        'name' => 'Testovací produkt',
        'ean' => '123456987123', // EAN musí být unikátní
        'code' => 'kod', // Nemusí být unikátní
        'sellPrice' => 99.9,
        'purchasePrice' => 99.9, // Nepovinné, výchozí nákupní cena
        'externalId' => 'vas-identifikator-123', // Nepovinné, externí identifikátor
    ],
    ['data' => ['id']]);
```
#### Úprava produktu
```php
$result = $depoto->mutation('updateProduct', 
    [
        'id' => $id, // ID produktu v Depotu
        'name' => 'Nový název',
    ],
    ['data' => ['id']]);
```
#### Vytvoření souboru/obrázku
```php
$result = $depoto->mutation('createFile', 
    [
        'text' => 'Popis souboru',
        'originalFilename' => 'původní název souboru.jpg',
        'mimeType' => 'image/jpeg',
        'product' => $productId, // ID produktu v Depotu
        'base64Data' => '...', // jen base64 data bez mimeType na začátku
    ],
    ['data' => ['id']]);    
```
#### Vytvoření zákazníka
```php
$resultCustomer = $depoto->mutation('createCustomer', 
    [
        'firstName' => 'Jméno',
        'lastName' => 'Příjmení',
        'email' => 'email@email.cz',
        'phone' => '777123456',
    ],
    ['data' => ['id']]);
```
#### Vytvoření adresy
```php
$resultAddress = $depoto->mutation('createAddress', 
    [
        'customer' => $resultCustomer['data']['id'],  // Nepovinné
        'firstName' => 'Jméno',
        'lastName' => 'Příjmení',
        'email' => 'email@email.cz',
        'phone' => '777123456',
        'street' => 'Ulice 12',
        'city' => 'Město',
        'zip' => '28903',
        'country' => 'CZ', // ISO 3166-1 alpha-2 country code
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
            [
                'product' => 123, 
                'code' => 'ABCD', 
                'type' => 'product', 
                'quantity' => 2, 
                'price' => 123.45, 
                'vat' => 12345
            ], // name na produktu je nepovinný, Depoto si jej dotáhne.
            [
                'code' => 'volna1', 
                'name' => 'Volná položka servisní poplatek', 
                'type' => 'product', 
                'quantity' => 1, 
                'price' => 666, 
                'vat' => 12345
            ], // Volná položka
            [
                'code' => 'doprava', 
                'name' => 'Doprava za stovku', 
                'type' => 'shipping', 
                'quantity' => 1, 
                'price' => 100, 
                'vat' => 12345
            ],
            [
                'code' => 'platba', 
                'name' => 'Dobírka za dvacku',
                'type' => 'payment', 
                'quantity' => 1, 
                'price' => 20, 
                'vat' => 12345
            ],
        ],
        'paymentItems' => [
            [
                'payment' => 789, 
                'amount' => 589.5,
                'isPaid' => true // Zaplaceno - ano/ne
            ],
        ],
    ]
    ['data' => ['id']]);
```
#### Přidání položky do objednávky
```php
$result = $depoto->mutation('createOrderItem', 
    [
        'order' => 123,
        'product' => 123,
        'quantity' => 5,
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
        'quantity' => 5,
    ],
    ['data' => ['id']]);    
```
#### Smazání položky objednávky
```php        
$result = $depoto->mutation('deleteOrderItem', 
    ['id' => $id],
    ['errors']);    
```
#### Úprava objednávky
```php    
$result = $depoto->mutation('updateOrder', 
    [
        'id' => $id,
        'shippingAddress' => 753951, // Změna doručovací adresy
        'items' => [
            ['product' => 123, 'quantity' => 2, 'price' => 123.45], // Nové položky
            // items a typy obdobně viz createOrder
        ],
        'paymentItems' => [
            [
                'payment' => 789, 
                'amount' => 589.5,
                'isPaid' => true // Zaplaceno - ano/ne
            ],
        ],
    ],
    ['data' => ['id']]);
```
#### Zrušení rezervace
Zručit jde jen objednávky ve stavu rezervace (status="reservation").
```php    
$result = $depoto->mutation('deleteReservation', 
    ['id' => $id],
    ['errors']]);      
```
