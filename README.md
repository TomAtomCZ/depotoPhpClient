# Depoto Php Client

## Použití
```
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
