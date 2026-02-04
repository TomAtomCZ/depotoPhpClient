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
[Čtěte best practices ve WIKI !!!](https://github.com/TomAtomCZ/depotoPhpClient/wiki):

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
    ], 'errors']);
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
    ], 'errors']);
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
    ['data' => ['id'], 'errors']);
```
#### Úprava produktu
```php
$result = $depoto->mutation('updateProduct', 
    [
        'id' => $id, // ID produktu v Depotu
        'name' => 'Nový název',
    ],
    ['data' => ['id'], 'errors']);
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
    ['data' => ['id'], 'errors']);    
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
    ['data' => ['id'], 'errors']);
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
        'branchId' => 123456, // Nepovinné, identifikátor pobočky např. výdejny pro zásilkovnu, balíkovnu a ostatní výdejny.
        'isStored' => 0, // 0/1 ... 0 pro jednorázové adresy určené pro createOrder. 1 pokud zakládáte adresu pro zákazníka (spolu s createCustomer) a chcete, aby šlo o výchozí adresu, se kterou se bude pracovat na kartě zákaznníka = půjde vybrat do dopravy či platby. V takovém případě je nutné vyplnit pole customer
        'isBilling' => 0, // 0/1 ... 0 = doručovací adresa, 1 = fakturační adresa 
    ],
    ['data' => ['id'], 'errors']);
```
#### Vytvoření objednávky
```php
$result = $depoto->mutation('createOrder', 
    [
        'status' => 'reservation',
        'customer' => $resultCustomer['data']['id'], // Nepovinné
        'invoiceAddress' => $resultAddress['data']['id']],
        'shippingAddress' => $resultAddress['data']['id']],
        'checkout' => 123, // ID pokladny daného eshopu
        'currency' => 'CZK',
        'carrier' => 'ppl',
        'items' => [
            [
                'product' => 123, 
                'code' => 'ABCD', 
                'name' => 'Název produktu', 
                'type' => 'product', 
                'quantity' => 2, 
                'price' => 123.45, // cena s DPH
                'vat' => 12345
            ], // name na produktu je nepovinný, Depoto si jej dotáhne. Je však doporučené jej posílat.
            [
                'product' => 456, 
                'code' => 'XYZ', 
                'name' => 'Název zlevněného produktu', 
                'type' => 'product', 
                'quantity' => 2, 
                'sale' => 20, // 20% sleva 
                'price' => 123.45, // základní cena s DPH před slevou 
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
                'isPaid' => true // Zaplaceno - true/false
            ],
        ],
    ]
    ['data' => ['id'], 'errors']);
```
#### Přidání položky do objednávky
```php
$result = $depoto->mutation('createOrderItem', 
    [
        'order' => 123,
        'product' => 123,
        'quantity' => 5,
        'price' => 31.5,
        'sale' => 10, // 10% z výše uvedené ceny s DPH
        'vat' => 666,
    ],
    ['data' => ['id'], 'errors']);
```
#### Úprava položky objednávky
```php    
$result = $depoto->mutation('updateOrderItem', 
    [
        'id' => $id,
        'quantity' => 5,
    ],
    ['data' => ['id'], 'errors']);    
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
    ['data' => ['id'], 'errors']);
```
#### Zrušení rezervace
Zručit jde jen objednávky ve stavu rezervace (status="reservation").
```php    
$result = $depoto->mutation('deleteReservation', 
    ['id' => $id],
    ['errors']]);      
```
#### Vytvoření skladového pohybu
```php    
$result = $depoto->mutation('createProductMovePack', 
    [
        'type' => "in", // "out" pro výdej, resp. "transfer" pro převodku
        'moves' => [
            [
                'product' => 123,
                'depotTo' => 666, // ID skladu. Pokud jde o type "out", použijte "depotFrom"
                'amount' => 1,
                'supplier' => 123, // ID dodavatele
                'purchasePrice' => 10.01,
                'purchaseCurrency' => 'CZK',
                'note' => 'Sync'
            ],
            // moves pro další produkty v pohybu
        ],
    ],
    ['data' => ['id'], 'errors']);
```


