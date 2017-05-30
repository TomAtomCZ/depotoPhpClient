# Zakladni popis Depoto API

## Potrebne odkazy

* GraphQL [dokumentace](http://graphql.org/learn/)

* [nástroj pro zobrazení GraphQL endpointu + testování (GraphiQL / ChromeiQL)](https://chrome.google.com/webstore/detail/chromeiql/fkkiamalmpiidkljmicmjfbieiclmeij)

* [Depoto root schema url](https://server1.depoto.cz/graphql)

* [Ukazka implementace v PHP](https://github.com/TomAtomCZ/depotoPhpClient)


> tento dokument slouzi pro rychle seznameni se zakladnimi principy
>
> **pro vsechny aktualni metody Depoto API a jejich specifikaci prejdete na generovane [schema](https://server1.depoto.cz/graphql)** (zobrazitelne napr. prostrednictvim ChromeiQLu)


## Zakladni nastaveni

> **veskere kroky - jsou-li pro vas jednorazove - je mozne take naklikat v [Depoto Client](https://client.depoto.cz) aplikaci**

> nasledujici query a mutace lze primo testovat napr. zkopirovanim do ChromeiQLu

* je treba zalozit **vyrobce** (vrati **id zalozeneho vyrobce**):
```
mutation {createProducer(name: "vyrobce1") {
  data {
    id
  }
  errors                # pripadne chyby (string[])
}}
```

* zalozte **dodavatele** (vrati **id zalozeneho dodavatele**):
```
mutation {createSupplier(name: "dodavatel1") {
  data {
    id
  }
  errors                # pripadne chyby (string[])
}}
```

* zalozte **dan** (vrati **id zalozene dane**):
```
mutation {createVat(
  name: "dph21"
  percent: 21           # procentualni hodnota
  default: true         # jedna se o defaultni dan
  ) {
  data {
    id
  }
  errors                # pripadne chyby (string[])
}}
```

* zalozte **platebni metodu** (vrati **id zalozene platby**):
```
mutation {createPayment(
  name: "hotove"
  type: "cash"          # typ platby (viz. nize)
  eetEnable: true       # pouzitelna v EET
  ) {
  data {
    id
  }
  errors                # pripadne chyby (string[])
}}

# typy plateb:

# bank_account      Převod na bankovní účet
# card	            Kartou
# cash	            Hotově
# cash_on_delivery  Dobírka
# coupon            Kupon | poukazka
```

* zalozte **sklad** (vrati **id zalozeneho skladu**):
```
mutation {createDepot(name: "sklad1") {
  data {
    id
  }
  errors            # pripadne chyby (string[])
}}
```

* zalozte **EET provozovnu** (vrati **id zalozene provozovny**):
```
mutation {createShop(
  name: "prodejna1"                 # nazev provozovny
  street: "ulice"                   # adresa - ulice
  city: "mesto"                     # adresa - mesto
  country: "czech"                  # adresa - stat
  zip: 10000                        # adresa - psc
  billFooter: "multiline string"    # paticka uctenky
  eetId: 123                        # id provozovny dle fin. spravy
  cert: { eet certificate id }      # id certifikatu (nahrajte prez Depoto Client)
  checkouts: [1, 2, 3]              # pole pokladen prirazenych k provozovne (id)
  ) {
  data {
    id
  }
  errors                            # pripadne chyby (string[])
}}
```

* zalozte **pokladnu** (vrati **id zalozene pokladny**):
```
mutation {createCheckout(
  name: "pokladna1"                 # nazev pokladny
  amount: 0                         # stav kasy (kc)
  nextReservationNumber: 1          # cislo pristi rezervace
  nextBillNumber: 1                 # cislo pristi objednavky (uctenky)
  billFooter: "multiline string"    # paticka uctenky
  payments: [1, 2, 3]               # pole povolenych plateb (id)
  depots: [1, 2, 3]                 # pole napojenych skladu (id)
  shop: { eet shop id }             # id EET provozovny
  eetEnable: true                   # povolit EET na pokladne
  eetPlayground: true               # prepne na testovaci servery fin. spravy
  eetVerificationMode: true         # posila overovaci zpravy na servery fin. spravy, nevraci FIK
  ) {
  data {
    id
  }
  errors                            # pripadne chyby (string[])
}}
```


## Naskladneni produktu a vytvoreni objednavky

* vytvorte **produkt** (vrati **id zalozeneho produktu**):
```
mutation {createProduct(
  name: "produkt1"  # nazev produktu
  # parent: { product id }          # nastavenim produkt id nadrazeneho produktu vytvorite variantu
  producer: { producer id }         # id pozadovaneho vyrobce
  ean: 123456789                    # vlastni identifikator - ean
  code: 987654321                   # vlastni identifikator - kod produktu
  sellPrice: 123                    # prodejni cena
  weight: 1                         # hmotnost
  enabled: true                     # urceno k prodeji (aktivni)
  vat: { vat id }                   # id pozadovane dane
  isForSubsequentSettlement: false  # polozka pro nasledne uplatneni (darkove poukazy)
  ) {
  data {
    id
  }
  errors                            # pripadne chyby (string[])
}}
```

* naskladnete **produkt** (vrati **id zalozeneho pohybu**):
```
mutation {createProductMovePack(
  type: "in"                        # typ pohybu (in | out | transfer)
  note: "multiline poznamka"        # poznamka
  moves: [                          # pohyby
    {
        product: { product id }             # id produktu
        # depotFrom: { depot id }           # ze skladu (id) - pri typu "out" a "transfer"
        depotTo: { depot id }               # na sklad (id) - pri typu "in" a "transfer"
        # productDepot: { productDepot id } # konkretni skladova zasoba (id) - pri typu "out" a "transfer"
        supplier: { supplier id }           # id dodavatele
        purchasePrice: 123                  # nakupni cena
        note: "multiline poznamka"          # poznamka
    }
  ]
  ) {
  data {
    id
  }
  errors                            # pripadne chyby (string[])
}}
```

* vyhledejte **produkty**:

> ma stejne chovani jako ostatni list queries (orders, checkouts, payments, ...)

> filtry je mozne doplnit zastupnym znakem * (chova se jako LIKE%)

```
query {products(filters: {
    name: string
    ean: string
    code: string
    fulltext: string
    depots: int[]             # id skladu
    suppliers: int[]          # id dodavatelu
    producers: int[]          # id vyrobcu
    enabled: bool
    availability: string      # available | reservation | stock | all (dostupne, v rezervaci, skladem, vse)
    listType: "parents"       # typ produktu, viz nize
}){
  items {
    id
    name
    ...
  }
  errors                      # pripadne chyby (string[])
}}

# typy produktu:
parents                                 Hlavní produkty
parents_without_children                Hlavní s variantami
parents_with_children                   Hlavní bez variant
children                                Varianty
children_and_parents_without_children   Skladové položky
all                                     Vše
```

* detail konkretniho **produktu**:

> ma stejne chovani jako ostatni detail queries (order, checkout, payment, ...)
```
query {product(id: 123){    # id produktu
  data {
    id
    name
    ...
  }
  errors                    # pripadne chyby (string[])
}}
```

* zalozte **rezervaci**

> **!POZOR!** je nutne mit prirazeny alespon jeden sklad (obsahujici pozadovane zbozi) k pokladne! **!POZOR!** 

```
mutation {createOrder(
  status: "reservation"             # stav (reservation | bill)
  checkout: { checkout id }         # id pokladny
  customer: { customer id }         # id zakaznika
  invoiceAddress: { address id }    # id fakturacni adresy
  shippingAddress: { address id }   # id dorucovaci adresy
  note: "multiline poznamka"        # poznamka
  privateNote: "multiline poznamka" # skryta poznamka (netiskne se na uctenky)
  rounding: 0.3                     # zaokrouhleni celkove ceny
  currency: "CZK"                   # mena (CZK, EUR, PLN)
  items: [                          # polozky | produkty
    {
        product: { product id }             # id produktu (je-li polozka produktem)
        note: "multiline poznamka"          # poznamka
        name: "polozka123"                  # nazev polozky | produktu
        quantity: 1                         # pocet kusu
        type: "product"                     # typ polozky (product | shipping | payment)
        sale: 5                             # sleva v % z prodejni ceny polozky
        price: 123                          # prodejni cena
        vat: { vat id }                     # dan (id)
        ean: 123456789                      # vlastni identifikator - ean
        code: 987654321                     # vlastni identifikator - kod produktu
        serial: "multiline serials"         # pripadna seriova cisla konkretnich polozek (oddelena newline)
        isForSubsequentSettlement: false    # polozka pro nasledne uplatneni (darkove poukazy)
    }
  ]
  paymentItems: [
    {
        checkout: { checkout id }           # id pokladny
        payment: { payment id }             # id platebni metody
        amount: 123                         # castka
        dateCreated: "2017-12-31 12:34:56"  # datum vytvoreni
        # dateCancelled: "2017-12-31 12:34:56"  # datum stornovani - pro zruseni platby
    }
  ]
  ) {
  data {
    id
  }
  errors                                    # pripadne chyby (string[])
}}
```

* potvrdte **objednavku** zmenou `status` na hodnotu `bill`

> **!POZOR!** je nutne mit prirazeny alespon jeden sklad (obsahujici pozadovane zbozi) k pokladne! **!POZOR!** 

```
mutation {updateOrder(
  id: { order id }                  # id objednavky
  status: "bill"                    # stav (reservation | bill)
  checkout: { checkout id }         # id pokladny
  customer: { customer id }         # id zakaznika
  invoiceAddress: { address id }    # id fakturacni adresy
  shippingAddress: { address id }   # id dorucovaci adresy
  note: "multiline poznamka"        # poznamka
  privateNote: "multiline poznamka" # skryta poznamka (netiskne se na uctenky)
  rounding: 0.3                     # zaokrouhleni celkove ceny
  currency: "CZK"                   # mena (CZK, EUR, PLN)
  items: [                          # polozky | produkty
    {
        product: { product id }             # id produktu (je-li polozka produktem)
        note: "multiline poznamka"          # poznamka
        name: "polozka123"                  # nazev polozky | produktu
        quantity: 1                         # pocet kusu
        type: "product"                     # typ polozky (product | shipping | payment)
        sale: 5                             # sleva v % z prodejni ceny polozky
        price: 123                          # prodejni cena
        vat: { vat id }                     # dan (id)
        ean: 123456789                      # vlastni identifikator - ean
        code: 987654321                     # vlastni identifikator - kod produktu
        serial: "multiline serials"         # pripadna seriova cisla konkretnich polozek (oddelena newline)
        isForSubsequentSettlement: false    # polozka pro nasledne uplatneni (darkove poukazy)
    }
  ]
  paymentItems: [
    {
        checkout: { checkout id }           # id pokladny
        payment: { payment id }             # id platebni metody
        amount: 123                         # castka
        dateCreated: "2017-12-31 12:34:56"  # datum vytvoreni
        # dateCancelled: "2017-12-31 12:34:56"  # datum stornovani - pro zruseni platby
    }
  ]
  ) {
  data {
    id
  }
  errors                                    # pripadne chyby (string[])
}}
```

* pripadne zruste **rezervaci**

> neni mozne stornovat jiz potvrzenou objednavku!

```
mutation {deleteReservation(
  id: { order id }      # id objednavky
  ) {
  errors                # pripadne chyby (string[])
}}
```

## Priklad zalozeni produktu pomoci DepotoPhpClient

```php
$client = new \Depoto\Client('username', 'password', 'https://server1.depoto.cz');
$result = $client->mutation('createProduct',
        [
            'name' => 'productNameFoo',
            'producer' => 31,                # vyrobce s id 31 musi byt vytvoren drive
            'vat' => 31,                     # dan s id 31 musi byt vytvorena drive
            'ean' => '12345678ASDF',
            'code' => '12345678ASDF',
            'sellPrice' => 1234,
            'enabled' => true,
        ], [
            'returnSchema' => [
                'data' => [
                    'id',
                    'name',
                    'fullName',
                    'ean',
                    'code',
                    'sellPrice',
                    'enabled',
                    'producer' => ['id', 'name'],
                    'vat' => ['id', 'name'],
                ],
                'errors',
            ]
        ]);
$exit(var_dump($result));   # $result === returnSchema 
```


> tento dokument slouzi pro rychle seznameni se zakladnimi principy
>
> **pro vsechny aktualni metody Depoto API a jejich specifikaci prejdete na generovane [schema](https://server1.depoto.cz/graphql)** (zobrazitelne napr. prostrednictvim ChromeiQLu)
