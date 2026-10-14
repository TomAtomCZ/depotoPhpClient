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

### Omezení počtu požadavků (HTTP 429)
Depoto server omezuje zátěž API pro každého uživatele: počet souběžných požadavků a čas, který server zpracováním požadavků stráví.
Požadavek nad limit odmítne odpovědí `429 Too Many Requests` s hlavičkou `Retry-After` – počtem sekund, po kterých má smysl požadavek poslat znovu
(při příliš mnoha souběžných požadavcích 1 s, při vyčerpání časového rozpočtu 2 až 300 s).
Limit se vyhodnocuje hned po ověření uživatele, ještě než server začne GraphQL požadavek zpracovávat. Odmítnutý požadavek, včetně mutace, tedy vůbec neproběhl a je bezpečné ho poslat znovu.

Server zatím limity jen měří a nevynucuje. Vynucování se nezapne dřív, než s odpovědí 429 budou umět pracovat všechny konektory (viz níže).

#### Co dělá klient
* `query()`, `mutation()`, `batchQuery()` a `batchMutation()` na odpověď 429 počkají `Retry-After` sekund (chybějící, nečitelná nebo nulová hlavička = 1 s) a požadavek pošlou znovu, nejvýše 2× v rámci jednoho volání.
* Pokud server žádá delší čekání než 5 s, klient nečeká a výjimku vyhodí hned – zbytečně by blokoval worker.
* Pokud je i poslední odpověď 429, vyhodí `Depoto\Exception\RateLimitException`. Je potomkem `ServerException`, takže ji zachytí i stávající `catch (ServerException $e)`.
  `getRetryAfter()` vrací počet sekund z hlavičky `Retry-After` (u data převedený na zbývající sekundy), nebo `null`, pokud hlavička chybí či je nečitelná.
* `authenticate()` (žádost o token) se automaticky neopakuje, na odpověď 429 rovnou vyhodí `RateLimitException`.
* Každé opakování se zaloguje na úrovni `info`, konečné odmítnutí na úrovni `warning`.

Nastavení (výchozí hodnoty; `ClientFactory` vytváří klienta s výchozími hodnotami, případně je nastavte na vráceném klientovi):
```php
$depoto
    ->setRateLimitRetries(2) // kolikrát nejvýše opakovat jedno volání; 0 = neopakovat, výjimka hned
    ->setMaxRateLimitWait(5); // nejdelší čekání v sekundách před jedním opakováním
```

#### Co musí udělat aplikace (konektor)
* `RateLimitException` **nikdy nepolykejte**. Handler zprávy, který výjimku jen zaloguje (`catch (\Exception $e) { $logger->error(...); }`), by zprávu zahodil – např. objednávka by se tiše nenaimportovala.
  Zprávu je nutné zpracovat znovu později, ideálně až za `getRetryAfter()` sekund.
* Symfony Messenger: výjimku z handleru propusťte ven, nejlépe zabalenou do `RecoverableMessageHandlingException` s prodlevou podle `getRetryAfter()`:

```php
use Depoto\Exception\RateLimitException;
use Symfony\Component\Messenger\Exception\RecoverableMessageHandlingException;

try {
    $result = $depoto->mutation('createOrder', $arguments, ['data' => ['id'], 'errors']);
}
catch (RateLimitException $e) {
    // Zprávu zpracovat znovu za Retry-After sekund. Messenger počítá prodlevu v milisekundách.
    throw new RecoverableMessageHandlingException($e->getMessage(), 0, $e, ($e->getRetryAfter() ?? 60) * 1000);
}
catch (\Exception $e) {
    $this->logger->error($e->getMessage());
}
```

  * Čtvrtý parametr konstruktoru `$retryDelay` (v milisekundách) má `RecoverableMessageHandlingException` od symfony/messenger 7.2
    (ověřeno ve verzích 7.3.6 a 8.0.14). Ve verzi 6.4 (ověřeno v 6.4.19) ho konstruktor nemá – tam vyhoďte
    `new RecoverableMessageHandlingException($e->getMessage(), 0, $e)` a prodlevu určí `retry_strategy` transportu.
  * `RecoverableMessageHandlingException` vynutí opakování bez ohledu na `max_retries` – zpráva se zkouší, dokud neprojde.
  * Pokud výjimku jen propustíte ven (`throw $e;`), opakuje se podle `retry_strategy` transportu. Při nastavení `max_retries: 3, multiplier: 2`
    a výchozí prodlevě 1 s jsou to pokusy po 1, 2 a 4 sekundách, což při `Retry-After` až 300 s nemusí stačit a zpráva pak skončí ve failure transportu (nebo se zahodí, pokud žádný není).
* Mimo Messenger (cron, fronta úloh) nechte úlohu selhat a naplánujte ji znovu, neoznačujte ji jako hotovou.

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


