Parser obchodného registra SR
=============================

> Disclaimer / Prehlásenie:
> Kód poskytnutý je bez záruky a môže kedykoľvek prestať fungovať.
> Jeho funkčnosť je striktne naviazaná na generovanú štruktúru HTML elementov.
> Autor nie je povinný udržiavať kód aktuálny a funkčný, ani neposkytuje ku nemu žiadnu podporu.
> Kód bol sprístupnený na základe mnohých žiadostí vývojárov finančno-ekonomických aplikácií a (bohužiaľ) neschopnosti 
> úradných inštitúcií sprístupniť oficiálny prístup do verejnej databázy subjektov pomocou štandardného API rozhrania.


Inštalácia
==========

Kód je obsiahnutý v jedinom PHP súbore `ConnectorOrsr_standalone.php`.


Dependencie
===========

Potrebné PHP rozšírenia: `tidy`, `mbstring`, `dom`, `iconv`, `json`.


Použitie
========

// inicializacia API objektu
$orsr = new ConnectorOrsr_standalone;


Vyhľadávanie:
----------------


// Vyhľadanie zoznamu subjektov podľa mena:
$list = $orsr->findByPriezviskoMeno('Novák', 'Alojz');
$list = $orsr->findByObchodneMeno('Kováč');

// vyhladanie detailu subjektu
$detail = $orsr->findByICO('31411801');
$detail = $orsr->getDetailById(1319, 9);


Podporné metódy:
----------------

// zapneme priamy výstup údajov do prehliadača + local file caching
$orsr->debug = true;

// nastavenie formátu výstupu
$orsr->setOutputFormat('xml'); // xml|json|empty string

--------------------------------------------------
