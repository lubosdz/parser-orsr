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


Tipy na správne použitie
========================

* nepreťažujte server obchodného  registra ORSR - nie je známe, akú záťaž dokáže server zvládnuť. Preťažením servera znemožníte využitie služby sebe aj iným. Buďte etickí programátori.
* neoporúčame posielať požiadavky na server častejšie ako 1x za minútu. V žiadnom prípade nerobte hromadné odoslanie požiadaviek napr. 10 požiadaviek za sekundu - nerobia to ani webboty, lebo vedia, že môžu odpáliť server a dostať IP ban.
* cachujte odpovede (do databázy) zo servera ORSR tak, aby sa rovnaký request neopakoval aspoň 3 - 6 mesiacov. Údaje v Obchodnom registri sa menia veľmi zriedkavo. Cachovanie nie je súčasťou implementácie (ukladanie odpovedí do lokálneho súboru v debug móde nepovažujeme za cachovanie).


Inštalácia, dependencie, demo
=============================

* Kód je obsiahnutý v jedinom PHP súbore `ConnectorOrsr.php`.
* Potrebné PHP rozšírenia: `tidy`, `mbstring`, `dom`, `iconv`, `json`.
* Demo: [http://www.synet.sk/php/sk/360-ORSR-API-rozhranie-obchodny-register](http://www.synet.sk/php/sk/360-ORSR-API-rozhranie-obchodny-register)
* install manually or via composer:

```bash
$ composer require "lubosdz/parser-orsr" : "~1.0.0"
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
// zapneme priamy výstup údajov do prehliadača + local file caching
$orsr->debug = true;

// nastavenie formátu výstupu
$orsr->setOutputFormat('xml'); // xml|json|empty string
```

Príklad odpovede:
----------------

```
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
	[datum_vypisu] => 09.11.2019
)
```


Príklad implementácie (MVC framework)
-------------------------------------

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
				url: "/orsr/find-detail-by-ico",
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
---------

* 1.0.5 - [09.11.2019] Revert support for option uplny/ciastocny vypis. Extract Miesto podnikania, Veduci org. zlozky. Fixed parsing countries for foreigners. Updated docs.
* 1.0.4 - [08.11.2019] Added option uplny/ciastocny vypis. Extract additional attributes (den vymazu, dovod vymazu, zastupovanie), fix multiple company names & address without street (only city).
* 1.0.3 - [02.09.2019] Added method findByICO, code cleanup & formatting
* 1.0.2 - [14.05.2019] fixed PCRE unicode handling in different environments
* 1.0.1 - [11.03.2019] fixed bug PHP7+ compatability
* 1.0.0 - [12.09.2018] initial release
