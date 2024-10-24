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


Licencia
========

Kód obsiahnutý v súbore `ConnectorOrsr.php` je voľne distribuovateľný a modifikovateľný na súkromné ako aj komerčné účely.


Poznámka / Note
===============

> Obchodný register SR obsahuje len časť subjektov v podnikateľskom prostredí (cca 480 tis.).
> Neobsahuje údaje napr. o živnostníkoch alebo neziskových organizáciách.
> Tieto sa nachádzajú v ďalších verejne prístupných databázach (živnostenský register,
> register účtovných závierok, register právnických osôb). Pokiaľ hľadáte profesionálne
> riešenie s prístupom ku všetkých 1.7 mil. subjektom pozrite [https://bizdata.sk](https://bizdata.sk).
>
> Parser for Business Directory of Slovak Republic allows accessing cca 480k companies.
> However, it does not provide access to ie. enterpreneurs or unprofitable organizations,
> since these are not contained within the Business Directory.
> If you are looking for a professional solution with access to all 1.7 mil. of entities,
> take a look at [https://bizdata.sk](https://bizdata.sk).


Tipy na správne použitie
========================

* nepreťažujte server obchodného  registra ORSR - nie je známe, akú záťaž dokáže server zvládnuť. Preťažením servera znemožníte využitie služby sebe aj iným. Buďte etickí programátori.
* neoporúčame posielať požiadavky na server častejšie ako 1x za minútu. V žiadnom prípade nerobte hromadné odoslanie požiadaviek napr. 10 požiadaviek za sekundu - nerobia to ani webboty, lebo vedia, že môžu odpáliť server a dostať IP ban.
* cachujte odpovede (do databázy) zo servera ORSR tak, aby sa rovnaký request neopakoval aspoň 3 - 6 mesiacov. Údaje v Obchodnom registri sa menia veľmi zriedkavo. Cachovanie nie je súčasťou implementácie (ukladanie odpovedí do lokálneho súboru v debug móde nepovažujeme za cachovanie).


Inštalácia, dependencie, demo
=============================

* Kód je obsiahnutý v jedinom PHP súbore `ConnectorOrsr.php`.
* Potrebné PHP rozšírenia: `tidy`, `mbstring`, `dom`, `iconv`, `json`.
* Demo: [https://synet.sk/blog/php/360-ORSR-API-rozhranie-obchodny-register](https://synet.sk/blog/php/360-ORSR-API-rozhranie-obchodny-register)
* install manually or via composer:

```bash
$ composer require "lubosdz/parser-orsr" : "~1.1.0"
```

Použitie / API / Usage
======================

```
// inicializacia API objektu
$orsr = new \lubosdz\parserOrsr\ConnectorOrsr();
```

Vyhľadávanie:
--------------

```
// vyhľadanie zoznamu subjektov podľa mena/názvu:
$list = $orsr->findByPriezviskoMeno('Novák', 'Peter');
$list = $orsr->findByObchodneMeno('Matador'); // e.g. vypis.asp?ID=1319&SID=9&P=0
$list = $orsr->findByICO('31577890'); // always max. 1 item - array(subject_name => link)

// vyhľadanie detailu subjektu podľa ID/IČO:
$detail = $orsr->getDetailById(1319, 9); // from link "vypis.asp?ID=1319&SID=9"
$detail = $orsr->getDetailByICO('31577890');
```

Podporné metódy:
----------------

```
// zapneme priamy výstup údajov do prehliadača + local file caching into temp directory
$orsr->debug = true;
$orsr->dirCache = '/writable/temp/'; // debugging will attempt to save fetched page

// nastavenie formátu výstupu
$orsr->setOutputFormat('xml'); // xml|json|empty string

// (!) NOT RECOMMENDED - bez tidy extension + vypnute zobrazenim XML chyb
$orsr->useTidy = false;
$orsr->showXmlErrors = false;
```

Príklad odpovede:
----------------

```
// sample #1
$list = $orsr->findByObchodneMeno('Matador');

$list : array (
  'MATADOR Automotive Vráble, a.s.' => 'vypis.asp?ID=1319&SID=9&P=0',
  'MATADOR Automation, s. r. o.' => 'vypis.asp?ID=361195&SID=6&P=0',
  'MATADOR HOLDING, a.s.' => 'vypis.asp?ID=6014&SID=6&P=0',
  'MATADOR Industries, a. s.' => 'vypis.asp?ID=5962&SID=6&P=0',
  'MATADOR Tools, s. r. o.' => 'vypis.asp?ID=361231&SID=6&P=0',
  'MATADORFIX s.r.o.' => 'vypis.asp?ID=8202&SID=2&P=0',
  'MATADOR-TOYS, s. r. o.' => 'vypis.asp?ID=313211&SID=6&P=0',
)

// sample #2
$detail = $orsr->getDetailById(1319, 9); // z linky 'vypis.asp?ID=1319&SID=9&P=0'

$detail : Array
(
	[meta] => Array
		(
			[api_version] => 1.0.5
			[sign] => 6A36A4547DBAD50692BEB0C428AB4FC8
			[server] => localhost
			[time] => 09.11.2019 09:22:58
			[sec] => 2.421
			[mb] => 0.680
		)
	[prislusny_sud] => Bratislava I
	[oddiel] => Po
	[vlozka] => 1648/B
	[typ_osoby] => pravnicka
	[hlavicka] => Spoločnosť zapísaná v obchodnom registri Okresného súdu Bratislava I, oddiel Po, vložka 1648/B.
	[hlavicka_kratka] => OS Bratislava I, oddiel Po, vložka 1648/B
	[obchodne_meno] => Novak company s. r. o., organizačná zložka
	[likvidacia] => nie
	[adresa] => Array
		(
			[street] => Heydukova
			[number] => 9
			[city] => Bratislava
			[zip] => 81108
		)

	[ico] => 44443536
	[den_zapisu] => 16.10.2008
	[pravna_forma] => Podnik zahraničnej osoby (organizačná zložka podniku zahraničnej osoby)
	[predmet_cinnosti] => Array
		(
			[0] => kúpa tovaru na účely jeho predaja konečnému spotrebiteľovi /maloobchod/ alebo iným prevádzkovateľom živnosti /veľkoobchod/
			[1] => sprostredkovateľská činnosť v oblasti obchodu
			[2] => sprostredkovateľská činnosť v oblasti služieb
		)

	[veduci_organizacnej_zlozky] => Array
		(
			[name] => Jan Novák
			[street] => Semická
			[city] => Modřany Praha
			[country] => Česká republika
			[since] => 16.10.2008
			[number] => 3292/6
			[zip] => 414300
		)

	[konanie_menom_spolocnosti] => Vedúci organizačnej zložky je oprávnený robiť právne úkony v záležitostiach týkajúcich sa organizačnej zložky. Vedúci organizačnej zložky koná a podpisuje tak, že k napísanému alebo vytlačenému označeniu organizačnej zložky pripojí svoj podpis s uvedením svojej funkcie. Zakladateľ môže stanoviť interné pokyny, ktorými obmedzí konanie vedúceho organizačnej zložky.
	[dalsie_skutocnosti] => Spoločnosť bola založená zakladateľskou listinou o založení organizačnej zložky vo forme notárskej zápisnice N 229/2008, Nz 41147/2008 zo dňa 1.10.2008 v zmysle príslušných ustanovení z. č. 513/1991 Zb. Obchodný zákonník.
	[datum_aktualizacie] => 07.11.2019
	[datum_vypisu] => 31.12.2023
)
```


Príklad implementácie (MVC framework, e.g. [Yii](https://www.yiiframework.com/))
--------------------------------------------------------------------------------

OrsrController:

```php
<?php
use lubosdz\parserOrsr\ConnectorOrsr;

public function actionFindDetailByIco()
{
	$ico = empty($_GET['ico']) ? '' : htmlspecialchars(trim($_GET['ico']));
	$out = [];

	$connector = new ConnectorOrsr();
	$results = $connector->getDetailByICO($ico);

	return $this->asJson($out);
}

public function actionFindListByCompanyName()
{
	$term = empty($_GET['term']) ? '' : htmlspecialchars(trim($_GET['term']));
	$out = [];

	$connector = new ConnectorOrsr();
	$results = $connector->findByObchodneMeno($term);

	if($results && is_array($results)){
		foreach($results as $name => $link){
			$out[] = [
				'label' => $name,
				'value' => $link,
			];
		}
	}

	return $this->asJson($out);
}

public function actionCompanyDetail()
{
	$link = empty($_GET['h']) ? '' : htmlspecialchars($_GET['h']);
	$out = [];

	if($link){
		$connector = new ConnectorOrsr();
		$out = $connector->getDetailByPartialLink($link);
	}

	return $this->asJson($out);
}
```

View:

```html
<input type="text" id="company_ico" maxlength="8" />

<script type="text/javascript">

$("#company_ico").on("keyup", function(){
	var me = $(this), ico = $.trim(me.val()),
	if(8 === ico.length){
		if(!/^([\d])+$/.test(ico)){
			alert("Zadajte len číslice 0-9.");
		}else{
			$.ajax({
				url: "/orsr/find-detail-by-ico", // implement your own OrsrController
				data: {ico: ico},
				success: function (response) {
					if(response.ico != undefined && response.ico){
						console.log(response);
					}else{
						alert("No records found.");
					}
				}
			})
		}
	}
});

</script>
```


Changelog
=========

1.1.2 - 21.10.2024
------------------
* ENH - store fetched source link (permalink) as attribute "srcUrl" along with extracted data
* ENH - documentation improvements - see [demo](https://synet.sk/blog/php/360-ORSR-API-rozhranie-obchodny-register)

1.1.1 - 16.10.2024
------------------
* ENH - extract Kontrolná komisia
* ENH - multiple minor parsing improvements - hodnota akcií, štatutári, neštandardné poznámky osobách apod.

1.1.0 - 13.11.2023
------------------
* Fix - vrátime platný spis pre viac platných záznamov / subjektov s rovnakým IČO (getDetailByICO - #10)
* Enh - support requests delay options (msecDelayFetchUrl, delayAfterRequestCount) to prevent from rate limit ban

1.0.9 - 02.06.2023
------------------
* Support "Mestský súd" along with traditional "Okresný súd"
* Fix multiline company name with EOLs
* tests passing 8.2.3

1.0.8 - 15.02.2022
------------------
* Fix compatability with PHP 8.1+

1.0.7 - 06.01.2022
------------------
* Added unit tests passing PHP 5.6 - 8.1
* Updated endpoint URL to HTTP -> HTTPS
* Separate method for loading remote URL with configurable timeout (default 5 secs)
* Many parsing improvements
* Fix invalid UTF-8 chars for some foreign companies, strip off accents from HU, PL, CZ company names
* Parsing item dates - e.g. item since or eventDate
* Normalized currency conversion to EUR (e.g. vyska vkladu) if denominated in SKK
* minor BC break: attribute `likvidacia` now returns 1|0 instead of ano|nie
* Added new parsed sections:
	* Spoločnosť zrušená od
	* Právny dôvod zrušenia
	* Vyhlásenie konkurzu
	* Správca konkurznej podstaty
	* členský vklad
	* Zlúčenie, splynutie
	* Právny nástupca

1.0.6 - 25.08.2020
------------------
* Make tidy extension optional (NOT recommended, but for some hostings the only way to go)
* Minor improvements e.g. multiple whitespaces replaced with a single whitespace

1.0.5 - 09.11.2019
------------------
* Revert support for option uplny/ciastocny vypis
* Extract Miesto podnikania, Veduci org. zlozky
* Fixed parsing countries for foreigners
* Updated documentation

1.0.4 - 08.11.2019
------------------
* Added option uplny/ciastocny vypis
* Extract additional attributes (den vymazu, dovod vymazu, zastupovanie)
* fix multiple company names & address without street (only city)

1.0.3 - 02.09.2019
------------------
* Added method findByICO, code cleanup & formatting

1.0.2 - 14.05.2019
------------------
* fixed PCRE unicode handling in different environments

1.0.1 - 11.03.2019
------------------
* fixed bug PHP7+ compatability

1.0.0 - 12.09.2018
------------------
* initial release
