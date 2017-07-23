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

Kód obsiahnutý v súbore `ConnectorOrsr_standalone.php` je voľne distribuovateľný a modifikovateľný na súkromné ako aj komerčné účely.


Tipy na správne použitie
========================

* nepreťažujte server obchodného  registra ORSR - nie je známe, akú záťaž dokáže server zvládnuť. Preťažením servera znemožníte využitie služby sebe aj iným. Buďte etickí programátori.
* neoporúčame posielať požiadavky na server častejšie ako 1x za minútu. V žiadnom prípade nerobte hromadné odoslanie požiadaviek napr. 10 požiadaviek za sekundu - nerobia to ani webboty, lebo vedia, že môžu odpáliť server a dostať IP ban.
* cachujte odpovede zo servera ORSR tak, aby sa rovnaký request neopakoval aspoň 3 - 6 mesiacov. Údaje v registri sa menia veľmi zriedkavo.


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
// vyhľadanie zoznamu subjektov podľa mena/názvu:
$list = $orsr->findByPriezviskoMeno('Novák', 'Peter');
$list = $orsr->findByObchodneMeno('Matador'); // e.g. vypis.asp?ID=1319&SID=9&P=0

// vyhľadanie detailu subjektu podľa ID/IČO:
$detail = $orsr->getDetailById(1319, 9);
$detail = $orsr->getDetailByICO('31411801');
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
			[api_version] => 1.1.0
			[sign] => 6A36A4547DBAD50692BEB0C428AB4FC8
			[server] => localhost
			[time] => 23.07.2017 09:22:58
			[sec] => 3.937
			[mb] => 0.500
		)

	[prislusny_sud] => Nitra
	[oddiel] => Sa
	[vlozka] => 8/N
	[typ_osoby] => pravnicka
	[hlavicka] => Spoločnosť zapísaná v obchodnom registri Okresného súdu Nitra, oddiel Sa, vložka 8/N.
	[hlavicka_kratka] => OÚ Nitra, oddiel Sa, vložka 8/N
	[obchodne_meno] => MATADOR Automotive Vráble, a.s.
	[likvidacia] => nie
	[adresa] => Array
		(
			[street] => Staničná
			[number] => 1045
			[city] => Vráble
			[zip] => 95212
		)

	[ico] => 31411801
	[den_zapisu] => 01.05.1992
	[pravna_forma] => Akciová spoločnosť
	[predmet_cinnosti] => Array
		(
			[0] => výroba a odbyt nástrojov a foriem
			[1] => kúpa tovaru na účely jeho predaja iným prevádzkovateľom živnosti (veľkoobchod v rozsahu voľných živností)
			[2] => sprostredkovateľská činnosť v oblasti obchodu a služieb v rozsahu voľnej živnosti
			[3] => prenájom nehnuteľností
			[4] => výroba lisovaných a zvarovaných dielov, zostáv pre automobilový a neautomobilový priemysel
			[5] => povrchové úpravy kovov(katoforéza)
			[6] => montáž komponentov pre automobilový priemysel bez typového schválenia
			[7] => vykonávanie mimoškolskej vzdelávacej činnosti
			[8] => kúpa tovaru za účelom jeho predaja konečnému spotrebiteľovi (maloobchod v rozsahu voľných živností)
		)

	[statutarny_organ] => Array
		(
			[predstavenstvo] => Array
				(
					[0] => Array
						(
							[city] => Bratislava - Staré Mesto
							[function] => predseda
							[name] => Ing. Štefan Rosina  PhD.
							[number] => 7724/18
							[since] => 01.06.2011
							[street] => Pod vinicami
							[zip] => 81102
						)

					[1] => Array
						(
							[city] => Púchov
							[function] => podpredseda
							[name] => Ing. Miroslav Rosina  PhD.
							[number] => 1361/6
							[since] => 12.06.2012
							[street] => Vodárska
							[zip] => 02001
						)

					[2] => Array
						(
							[city] => Ivanka pri Dunaji
							[function] => člen
							[name] => Ing. Boris Sluka
							[number] => 80
							[since] => 12.06.2012
							[street] => Poľná
							[zip] => 90028
						)

					[3] => Array
						(
							[city] => Horovce
							[function] => člen
							[name] => Ing. Jozef Vozár
							[number] => 115
							[since] => 30.05.2013
							[street] => Horovce
							[zip] => 02062
						)

					[4] => Array
						(
							[city] => Púchov
							[function] => podpredseda
							[name] => Ing. Martin Kele
							[number] => 1157/17
							[since] => 16.07.2015
							[street] => Obrancov mieru
							[zip] => 02001
						)

				)

		)

	[konanie_menom_spolocnosti] => V mene spoločnosti koná a podpisuje predstavenstvo tak, žek vytlačenému alebo napísanému obchodnému menu spoločnosti a k označeniu funkcie pripojí svoj vlastnoručný podpis: predseda predstavenstva samostatne alebo podpredseda predstavenstva samostatne alebo dvaja členovia predstavenstva spolu
	[zakladne_imanie] => 20448124 EUR, Rozsah splatenia: 20448124 EUR
	[akcie] => Array
		(
			[0] => Array
				(
					[pocet] => 337816
					[druh] => v listinnej podobe
					[forma] => akcie na meno
					[menovita_hodnota] => 34 EUR
					[obmedzenie_prevoditelnosti_akcii_na_meno] => Predchádzajúci písomný súhlas predstavenstva. Predkupné právo spoločnosti.
				)

			[1] => Array
				(
					[pocet] => 27
					[druh] => v listinnej podobe
					[forma] => akcie na meno
					[menovita_hodnota] => 331940 EUR
					[obmedzenie_prevoditelnosti_akcii_na_meno] => Predchádzajúci písomný súhlas predstavenstva. Predkupné právo spoločnosti.
				)

		)

	[dozorna_rada] => Array
		(
			[0] => Array
				(
					[city] => Tajná
					[country] => Slovenská republika
					[function] =>
					[name] => Gabriel Nádašdy
					[number] => 27
					[since] => 16.12.1999
					[street] =>
					[zip] =>
				)

			[1] => Array
				(
					[city] => Banská Bystrica
					[country] => Slovenská republika
					[function] =>
					[name] => Ing. Inge Murgašová
					[number] => 18
					[since] => 21.05.2014
					[street] => Horná Mičiná
					[zip] => 97401
				)

			[2] => Array
				(
					[city] => Púchov
					[country] => Slovenská republika
					[function] => člen dozornej rady
					[name] => Ing. Juraj Hričovský
					[number] => 1775/5
					[since] => 01.06.2016
					[street] => Ružová
					[zip] => 02001
				)

		)

	[dalsie_skutocnosti] => Array
		(
			[0] => Na mimoriadnom valnom zhromaždení spoločnosti PALT a.s., konanom dňa 11.2.1993, ktorého priebeh je osvedčený v notárskej zápisnici napísanej dňa 11.2.1993, Nz 196/93 notárkou JUDr. Helenou Hrušovskou bola schválená zmena stanov akciovej spoločnosti. Stary spis: Sa 17
			[1] => Na valnom zhromaždení konanom dňa 30.9.1993 bola schválená zmena stanov a.s. Stary spis: Sa 17
			[2] => Zmena stanov akciovej spoločnosti schválená na valnom zhromaždení spoločnosti dňa 09.07.1996. Stary spis: Sa 17
			[3] => Zánik funkcie členov dozornej rady Ing. Jána Csáczára dňom 16.12.1999 a Johannesa Antoniusa Josepha Strengersa dňom 16.5.2001. Zmena stanov spoločnosti schválená na valnom zhromaždení dňa 16.12.1999. Zmena stanov spoločnosti schválená na valnom zhromaždení dňa 16.5.2001. Zmena stanov spoločnosti schválená na valnom zhromaždení dňa 20.3.2002. Zmena stanov spoločnosti schválená na valnom zhromaždení dňa 24.5.2002. Zmena stanov spoločnosti schválená na valnom zhromaždení dňa 2.12.2002.
			[4] => Na základe rozhodnutia riadneho valného zhromaždenia akcionárov spoločnosti MATADOR Automotive Vráble, a.s. zo dňa 23.05.2006 sa spoločnosť MATADOR Automotive Vráble, a.s. so sídlom Staničná 1045, Vráble, IČO: 31 411 801 zlučuje dňom 23.06.2006 so spoločnosťou Inalfa Nitra, a.s., so sídlom Novozámocká cesta 185 A, Nitra, IČO: 36 553 590. Zmluva o zlúčení vo forme notárskej zápisnice N 156/2006;Nz 14593/2006 zo dňa 18.04.2006. Spoločnosť MATADOR Automotive Vráble, a.s., so sídlom Staničná 1045, Vráble, IČO: 31 411 801 sa stáva univerzálnym nástupcom zanikajúcej spoločnosti Inalfa Nitra, a.s., so sídlom Novozámocká cesta 185 A, Nitra, IČO: 36 553 590, oddiel Sa, vložka číslo 10204/N a zápisom zlúčenia do Obchodného registra na ňu prechádzajú všetky práva a povinnosti so zanikajúcej spoločnosti.
		)

	[datum_aktualizacie] => 20.07.2017
	[datum_vypisu] => 23.07.2017
)
```

--------------------------------------------------
