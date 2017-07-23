Parser obchodného registra SR
=============================

> *Disclaimer / Prehlásenie*:
>
> Kód poskytnutý je bez záruky a môže kedykoľvek prestať fungovať.
> Jeho funkčnosť je striktne naviazaná na generovanú štruktúru HTML elementov.
> Autor nie je povinný udržiavať kód aktuálny a funkčný, ani neposkytuje ku nemu žiadnu podporu.
> Kód bol sprístupnený na základe mnohých žiadostí vývojárov finančno-ekonomických aplikácií a (bohužiaľ) neschopnosti
> úradných inštitúcií sprístupniť oficiálny prístup do verejnej databázy subjektov pomocou štandardného API rozhrania.
> Autor nezodpovedá za nesprávne použitie kódu.


Inštalácia, dependencie, demo
=============================

* Kód je obsiahnutý v jedinom PHP súbore `ConnectorOrsr_standalone.php`.
* Potrebné PHP rozšírenia: `tidy`, `mbstring`, `dom`, `iconv`, `json`.
* Demo: [http://www.synet.sk/php/sk/360-ORSR-API-rozhranie-obchodny-register](http://www.synet.sk/php/sk/360-ORSR-API-rozhranie-obchodny-register)


Použitie / API
==============

```
// inicializacia API objektu
$orsr = new ConnectorOrsr_standalone;
```

Vyhľadávanie:
----------------

```
// Vyhľadanie zoznamu subjektov podľa mena:
$list = $orsr->findByPriezviskoMeno('Novák', 'Alojz');
$list = $orsr->findByObchodneMeno('Kováč');

// vyhladanie detailu subjektu
$detail = $orsr->findByICO('31411801');
$detail = $orsr->getDetailById(1319, 9);
```

Podporné metódy:
----------------

```
// zapneme priamy výstup údajov do prehliadača + local file caching
$orsr->debug = true;

// nastavenie formátu výstupu
$orsr->setOutputFormat('xml'); // xml|json|empty string
```


Licencia
========

Kód obsiahnutý v súbore `ConnectorOrsr_standalone.php` je voľne distribuovateľný a modifikovateľný na súkromné ako aj komerčné účely.


Tipy na správne použitie
========================

* nepreťažujte server obchodného  registra ORSR - nie je známe, akú záťaž dokáže server zvládnuť. Preťažením servera znemožníte využitie služby sebe aj iným. Buďte etickí programátori.
* neoporúčame posielať požiadavky na server častejšie ako 1x za minútu. V žiadnom prípade nerobte hromadné odoslanie požiadaviek napr. 10 požiadaviek za sekundu - nerobia to ani webboty, lebo vedia, že môžu odpáliť server a dostať IP ban.
* cachujte odpovede zo servera ORSR tak, aby sa rovnaký request neopakoval aspoň 3 - 6 mesiacov. Údaje v registri sa menia veľmi zriedkavo.

--------------------------------------------------
