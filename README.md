# Depoto Php Client

## Použití
```php
$client = new \Depoto\Client('username', 'password', 'https://server1.depoto.cz');

// Vytvoření účtenky
$client->createEetReceipt(
            $checkoutId, // ID pokladny v Depoto
            $number, // Číslo účtenky
            $dateCreated, // Datum vytvoření, string, např: date('Y-m-d h:is')
            $currency, // Měna, např: CZK
            $totalPrice,// Celková cena
            $priceZeroVat = null, // Částka v nulové sazbě dph
            $priceStandardVat = null, // Základ daně v základní sazbě dph
            $vatStandard = null, // Částka v základní sazbě dph
            $priceFirstReducedVat = null, // Základ daně v první snížené sazbě dph
            $vatFirstReduced = null, // Částka v první snížené sazbě dph
            $priceSecondReducedVat = null, // Základ daně v druhé snížené sazbě dph
            $vatSecondReduced = null, // Částka v druhé snížené sazbě dph
            $priceForSubsequentSettlement = null, // Částka k následnému čerpání
            $priceUsedSubsequentSettlement = null // Následně čerpaná částka
            );
            
// Manuální znovuodeslání účtenky, ID účtenky v Depoto            
$client->sendEetReceipt($id); 

// Výpis účtenek
$client->getEetReceipts($page = 1, $sort = 'id', $direction = 'asc', $filters = []);
```

## Použití s vlastní query / mutací

#### úvod do GraphQL

* [GraphQL dokumentace](http://graphql.org/learn/)

#### přehled Depoto API

* [nástroj pro zobrazení GraphQL endpointu + testování (GraphiQL / ChromeiQL)](https://chrome.google.com/webstore/detail/chromeiql/fkkiamalmpiidkljmicmjfbieiclmeij)

* [Depoto root schema url](https://server1.depoto.cz/graphql)


```php
$client = new \Depoto\Client('username', 'password', 'https://server1.depoto.cz');

$schema = ['id', 'name'];
$result = $client->query('queryName', 
        ['arg1' => $arg1, 'arg2' => $arg2],
        ['returnSchema' => $schema]);
```

#### Zakladni prehled

* [Informace o zakladnich API metodach](./API_BASICS.md)
