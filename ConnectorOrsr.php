<?php
/**
* Parser pre vypis z obchodneho registra SR
* Lookup service for Slovak commercial register (www.orsr.sk)
*
* Version 1.0.3 (released 02.09.2019)
* (c) 2015 - 2019 lubosdz@gmail.com
*
* ------------------------------------------------------------------
* Disclaimer / Prehlásenie:
* Kód poskytnutý je bez záruky a môže kedykoľvek prestať fungovať.
* Jeho funkčnosť je striktne naviazaná na generovanú štruktúru HTML elementov.
* Autor nie je povinný udržiavať kód aktuálny a funkčný, ani neposkytuje ku nemu žiadnu podporu.
* Kód bol sprístupnený na základe mnohých žiadostí vývojárov finančno-ekonomických aplikácií a (bohužiaľ) neschopnosti
* úradných inštitúcií sprístupniť oficiálny prístup do verejnej databázy subjektov pomocou štandardného API rozhrania.
* Autor nezodpovedá za nesprávne použitie kódu.
* ------------------------------------------------------------------
*
* Source:
* https://github.com/lubosdz/parser-orsr
* ------------------------------------------------------------------
*
* Usage examples:
*
* // init object
* $orsr = new \lubosdz\parserOrsr\ConnectorOrsr();
*
* // turn on debug mode (means save output to a local file to reduce requests)
* $orsr->debug = true;
* $orsr->dirCache = "/app/writable/cache/";
* $orsr->setOutputFormat('xml'); xml|json|empty string
*
* // make requests
* $orsr->getDetailById(1366, 9); // a.s. - Agrostav
* $orsr->getDetailById(19691, 2); // a.s. - Kerametal
* $orsr->getDetailById(11095, 2); // s.r.o. - Elet
* $orsr->getDetailById(11075, 5); // Firma / SZCO
* $orsr->getDetailById(5721, 6); // v.o.s.
* $orsr->getDetailById(11370, 6); // druzstvo
* $orsr->getDetailById(60321, 8); // statny podnik
*
* $orsr->getDetailByICO('31577890');
* $orsr->getDetailByICO('123');
*
* $data = $orsr->findByPriezviskoMeno('novák', 'peter');
* $data = $orsr->findByObchodneMeno('Matador');
* $data = $orsr->findByICO('31411801');
*
* $data = $orsr->getDetailByICO('31411801'); // [MATADOR Automotive Vráble, a.s.] => vypis.asp?ID=1319&SID=9&P=0
* echo "<pre>".print_r($data, 1)."</pre>";
*
* $data = $orsr->getDetailById(1319, 9); // statny podnik
* echo "<pre>".print_r($data, 1)."</pre>";
*/

namespace lubosdz\parserOrsr;

/**
* Slovak Business Register DOM XML parser
*/
class ConnectorOrsr
{
	const API_VERSION = '1.0.3';

	// by extracting target URL it's easier to update if they switch to secure URL or remove www prefix etc.
	const URL_BASE = 'http://www.orsr.sk';

	const TYP_OSOBY_PRAVNICKA = 'pravnicka';
	const TYP_OSOBY_FYZICKA = 'fyzicka';

	// stores some data into local files to avoid multiple requests during development
	public $debug = false;

	// path to cache directory in debug mode (add trailing slash)
	public $dirCache;

	// execution start time
	protected $ts_start;

	// output format JSON|XML|RAW
	protected $format;

	// extracted data
	protected $data = [];

	// semaphore to avoid double output, e.g. if matched 1 item (DIC, ICO) we instantly return detail
	protected $outputSent = false;

	/**
	* Constructor
	*/
	public function __construct()
	{
		$this->ts_start = microtime(true);

		foreach(['tidy', 'mbstring', 'iconv', 'dom', 'json'] as $extension){
			if(!extension_loaded($extension)){
				exit('Missing required PHP extension ['.$extension.'].');
			}
		}
	}

	public function resetOutput()
	{
		$this->outputSent = false;
		$this->data = [];
	}

	public function setOutputFormat($format)
	{
		$format = trim(strtolower($format));

		if(in_array($format, ['json', 'xml', ''])){
			$this->format = $format;
		}else{
			throw new \Exception('Output format ['.$format.'] not supported.');
		}

		return $this;
	}

	/**
	* Return only data required for formulars
	* @param [] $data Data from requested service, which possibly will not contain ALL available attributes
	* @param [] $force List of required attributes. Additional queries will be executed, if required attribute is empty
	*/
	public function normalizeData(array $data, array $force = [])
	{
		$out = [
			'ico' => empty($data['ico']) ? '' : $data['ico'], // e.g. 32631413
			'obchodne_meno' => empty($data['obchodne_meno']) ? '' : $data['obchodne_meno'],
			'street' => '',
			'number' => '',
			'city' => '',
			'zip' => '', // e.g. 90101
			'typ_osoby' => empty($data['typ_osoby']) ? '' : $data['typ_osoby'], // fyzicka - pravnicka
			'hlavicka' => empty($data['hlavicka']) ? '' : $data['hlavicka'], // Fyzicka osoba zapisana v OU Nitra vlozka 1234/B.
			'hlavicka_kratka' => empty($data['hlavicka_kratka']) ? '' : $data['hlavicka_kratka'], // OU Nitra, vlozka 1234/B
			'dic' => empty($data['dic']) ? '' : $data['dic'], // e.g. 1020218914
			'nace_kod' => empty($data['nace_kod']) ? '' : $data['nace_kod'], // e.g. 41209
			'nace_text' => empty($data['nace_text']) ? '' : $data['nace_text'], // e.g. Počítačové služby a poradenstvo
		];

		if(!empty($data['adresa']['street'])){
			$out['street'] = $data['adresa']['street'];
		}elseif(!empty($data['street'])){
			$out['street'] = $data['street'];
		}

		if(!empty($data['adresa']['number'])){
			$out['number'] = $data['adresa']['number'];
		}elseif(!empty($data['number'])){
			$out['number'] = $data['number'];
		}

		if(!empty($data['adresa']['city'])){
			$out['city'] = $data['adresa']['city'];
		}elseif(!empty($data['city'])){
			$out['city'] = $data['city'];
		}

		if(!empty($data['adresa']['zip'])){
			$out['zip'] = $data['adresa']['zip'];
		}elseif(!empty($data['zip'])){
			$out['zip'] = $data['zip'];
		}

		if($force){
			// load missing required attributes
			foreach($force as $attribute){
				if(!empty($out[$attribute])){
					continue; // already set
				}
				switch($attribute){
					case 'hlavicka':
					case 'hlavicka_kratka':
						if($data['typ_osoby'] == 'pravnicka'){
							$orsr = new ConnectorOrsr();
							$extra = $orsr->getDetailByICO($data['ico']);
							if(empty($extra['prislusny_sud'])){
								$link = current($extra);
								$extra = $orsr->getDetailByPartialLink($link);
							}
							if(!empty($extra['hlavicka'])){
								if(!empty($extra['hlavicka'])){
									$out['hlavicka'] = $extra['hlavicka'];
								}
								if(!empty($extra['hlavicka_kratka'])){
									$out['hlavicka_kratka'] = $extra['hlavicka_kratka'];
								}
							}
						}else{
							// parser ZRSR.SK not implemented yet
						}
						break;
					default:
				}
			}
		}

		return $out;
	}

	/**
	* Return output with extra meta data
	*/
	public function getOutput()
	{
		if($this->outputSent){
			// prevent from duplicate output
			return;
		}

		if(!$this->data){
			// nothing to return
			return;
		}

		if(!is_array($this->data)){
			throw new \Exception('Invalid output data.');
		}

		if(empty($this->data['meta'])){
			// meta data not included
			$this->data = ['meta' => [
				'api_version' => self::API_VERSION,
				'sign' => strtoupper(md5(serialize($this->data))),
				'server' => $_SERVER['SERVER_NAME'],
				'time' => date('d.m.Y H:i:s'),
				'sec' => number_format(microtime(true)-$this->ts_start, 3),
				'mb' => number_format(memory_get_usage()/1024/1024, 3),
			]] + $this->data;
		}

		$this->outputSent = true;

		if($this->debug){

			switch(strtolower($this->format)){
				case 'json':
					header("Content-Type: application/json; charset=UTF-8");
					echo json_encode($this->data);
					break;
				case 'xml':
					header("Content-Type: text/xml; charset=UTF-8");
					echo _Array2XML::get($data);
					break;
				default:
					// raw format
					header("Content-Type: text/html; charset=UTF-8");
					echo '<pre>'.print_r($this->data, true).'</pre>';
			}

		}else{
			return $this->data;
		}
	}

	/**
	* Fetch company page from ORSR and return parsed data
	* @param int $id Company database identifier, e.g. 19456
	* @param int $sid ID 0 - 8, prislusny sud/judikatura (jurisdiction district ID)
	* @param int $p 0|1 Typ vypisu, default 0 = aktualny, 1 - uplny (vratane historickych zrusenych zaznamov)
	*/
	public function getDetailById($id, $sid, $p = 0)
	{
		$id = intval($id);
		if($id < 1){
			exit('Invalid company ID.');
		}

		$hash = $id.'-'.$sid.'-'.$p;
		$path = $this->dirCache.'orsr-detail-'.$hash.'-raw.html';

		if($this->debug && is_file($path) && filesize($path)){
			$html = file_get_contents($path);
		}else{
			// ID + SID = jedinecny identifikator subjektu
			// SID (ID sudu dla kraja) = 1 .. 8 different companies :-(
			// P = 1 - uplny, otherwise 0 = aktualny
			$url = self::URL_BASE."/vypis.asp?ID={$id}&SID={$sid}&P={$p}";
			$html = file_get_contents($url);
			if($html && $this->debug){
				file_put_contents($path, $html);
			}
		}

		if(!$html){
			throw new \Exception('Failed loading data.');
		}

		$this->data = self::extractDetail($html);

		return $this->getOutput();
	}

	/**
	* Fetch company page from ORSR and return parsed data
	* @param string $link Partial link to fetch, e.g. vypis.asp?ID=54190&SID=7&P=0
	* @param string $uplnyVypis 1|true = ano, 0|false = nie, inak podla vratenej linky z ORSR
	*/
	public function getDetailByPartialLink($link, $uplnyVypis = null)
	{
		$data = [];

		if(false !== strpos($link, 'vypis.asp?')){
			// ID + SID = jedinecny identifikator subjektu
			// SID (ID sudu dla kraja) = 1 .. 8 different companies :-(
			// P = 1 - uplny, 0 - aktualny
			list(, $link) = explode('asp?', $link);
			parse_str($link, $params);

			if(true === $uplnyVypis || '1' == trim($uplnyVypis)){
				$params['P'] = 1;
			}elseif(false === $uplnyVypis || '0' === trim($uplnyVypis)){
				$params['P'] = 0;
			}

			if(isset($params['ID'], $params['SID'], $params['P'])){
				$data = $this->getDetailById($params['ID'], $params['SID'], $params['P']);
			}
		}

		return $data;
	}

	/**
	* Return subject details
	* @param string $meno
	*/
	public function findByObchodneMeno($meno)
	{
		$meno = trim($meno);
		$meno = iconv('utf-8', 'windows-1250', $meno);
		$meno = urlencode($meno);

		$path = $this->dirCache.'orsr-search-obmeno-'.$meno.'.html';

		if($this->debug && is_file($path) && filesize($path)){
			$html = file_get_contents($path);
		}else{
			// http://www.orsr.sk/hladaj_subjekt.asp?OBMENO=sumia&PF=0&R=on
			// R=on ... only aktualne zaznamy, otherwise hladaj aj v historickych zaznamoch
			// PF=0 .. pravna forma (0 = any)
			$url = self::URL_BASE."/hladaj_subjekt.asp?OBMENO={$meno}&PF=0&R=on";
			$html = file_get_contents($url);
			if($html && $this->debug){
				file_put_contents($path, $html);
			}
		}

		return $this->handleFindResponse($html);
	}

	/**
	* Lookup by subject ICO
	*
	* @param string $ico
	* @return array List of matching subjects e.g. suitable for autocomplete/typeahead fields
	*/
	public function findByICO($ico)
	{
		$ico = preg_replace('/[^\d]/', '', $ico);
		if(strlen($ico) != 8){
			return [];
		}

		$path = $this->dirCache.'orsr-search-ico-'.$ico.'.html';

		if($this->debug && is_file($path) && filesize($path)){
			$html = file_get_contents($path);
		}

		// http://www.orsr.sk/hladaj_ico.asp?ICO=123&SID=0
		// SID=0 .. sud ID (0 = any)
		if(empty($html)){
			$url = self::URL_BASE."/hladaj_ico.asp?ICO={$ico}&SID=0";
			$html = file_get_contents($url);
			if($html && $this->debug){
				file_put_contents($path, $html);
			}
		}

		// lookup by ICO always returns max. 1 record
		return $this->handleFindResponse($html);
	}

	/**
	* Looup by subject ICO & return instantly company/subject details
	* @param string $ico Company ID (8 digits code)
	* @param string $uplnyVypis 1|true = ano, 0|false = nie, inak podla vratenej linky z ORSR
	* @return array Company / subject details
	*/
	public function getDetailByICO($ico, $uplnyVypis = null)
	{
		$ico = preg_replace('/[^\d]/', '', $ico);
		if(strlen($ico) != 8){
			return [];
		}

		$path = $this->dirCache.'orsr-search-ico-'.$ico.'.html';

		if($this->debug && is_file($path) && filesize($path)){
			$html = file_get_contents($path);
		}

		// http://www.orsr.sk/hladaj_ico.asp?ICO=123&SID=0
		// SID=0 .. sud ID (0 = any)
		if(empty($html)){
			$url = self::URL_BASE."/hladaj_ico.asp?ICO={$ico}&SID=0";
			$html = file_get_contents($url);
			if($html && $this->debug){
				file_put_contents($path, $html);
			}
		}

		// lookup by ICO always finds max. 1 record
		$link = $this->handleFindResponse($html);

		if($link && is_array($link)){
			$link = current($link);
			$this->data = $this->getDetailByPartialLink($link, $uplnyVypis);
		}

		return $this->data;
	}

	/**
	* Search by surname and/or name
	* @param string $priezvisko
	* @param string $meno
	*/
	public function findByPriezviskoMeno($priezvisko, $meno = '')
	{
		$priezvisko = trim($priezvisko);
		$priezvisko = iconv('utf-8', 'windows-1250', $priezvisko);
		$priezvisko = urlencode($priezvisko);

		$meno = trim($meno);
		$meno = iconv('utf-8', 'windows-1250', $meno);
		$meno = urlencode($meno);

		$path = $this->dirCache.'orsr-search-priezvisko-meno-'.$priezvisko.'-'.$meno.'.html';

		if($this->debug && is_file($path) && filesize($path)){
			$html = file_get_contents($path);
		}else{
			// http://orsr.sk/hladaj_osoba.asp?PR=kov%E1%E8&MENO=&SID=0&T=f0&R=on
			// PR=priezvisko
			// MENO=meno
			// R=on ... only aktualne zaznamy, otherwise hladaj aj v historickych zaznamoch
			// PF=0 .. pravna forma (0 = any)
			// SID=0 .. sud ID (0 = any)
			$url = self::URL_BASE."/hladaj_osoba.asp?PR={$priezvisko}&MENO={$meno}&SID=0&T=f0&R=on";
			$html = file_get_contents($url);
			if($html && $this->debug){
				file_put_contents($path, $html);
			}
		}

		return $this->handleFindResponse($html, 'formaterMenoPriezvisko');
	}

	/**
	* Handle response from ORSR with search results
	* @param string $html Returned HTML page from ORSR
	* @param string $formatter Custom output decorator
	*/
	protected function handleFindResponse($html, $formatter = '')
	{
		$html = iconv('windows-1250', 'utf-8', $html);
		$html = str_replace('windows-1250', 'utf-8', $html);

		// ensure valid XHTML markup
		$tidy = new \tidy();
		$html = $tidy->repairString($html, array(
			'output-xhtml' => true,
			//'show-body-only' => true, // we MUST have HEAD with charset!!!
		), 'utf8');

		// load XHTML into DOM document
		$xml = new \DOMDocument('1.0', 'utf-8');
		$xml->loadHTML($html);
		$xpath = new \DOMXpath($xml);

		$rows = $xpath->query("/html/body/table[3]/tr/td[2]"); // all tables /html/body/table

		// loop through elements, parse & normalize data
		$out = [];
		if ($rows->length) {
			foreach ($rows as $row) {
				if($formatter && method_exists($this, $formatter)){
					$out += self::{$formatter}($row, $xpath);
				}else{
					// drop double quotes, firmy maju zahrnute uvodzovky v nazve spolocnosti
					$label = trim(str_replace('"', '', $row->nodeValue));
					$links = $xpath->query(".//../td[3]/div/a", $row);

					if($links->length){
						$linkAktualny = $links->item(0)->getAttribute('href'); // e.g. "vypis.asp?ID=208887&SID=3&P=0"
						$out[$label] = $linkAktualny;
					}
				}
			}
		}

		return $out;
	}

	/**
	* Partial XML node formatter
	* @param mixed $row
	* @param mixed $xpath
	*/
	protected static function formaterMenoPriezvisko($row, $xpath)
	{
		$label1 = trim($row->nodeValue);
		$label2 = $xpath->query(".//../td[3]", $row);
		$label2 = trim($label2->item(0)->nodeValue);
		$label = "{$label1} ({$label2})";

		$links = $xpath->query(".//../td[4]/div/a", $row);
		$linkAktualny = $links->item(0)->getAttribute('href'); // e.g. "vypis.asp?ID=208887&SID=3&P=0"

		return [$label => $linkAktualny];
	}

	/**
	* Extract tags
	* @param string $html
	*/
	protected function extractDetail($html)
	{
		// returned data
		$this->data = [];

		// extracted tags
		$tags = [
			'Výpis z Obchodného registra' 	=> 'extract_prislusnySud',
			'Oddiel'       				  	=> 'extract_oddiel',
			'Obchodné meno' 				=> 'extract_obchodneMeno',
			'Sídlo'         				=> 'extract_sidlo',
			'Bydlisko'         				=> 'extract_bydlisko',
			'IČO'             				=> 'extract_ico',
			'Deň zápisu'     				=> 'extract_denZapisu',
			'Deň výmazu'     				=> 'extract_denVymazu',
			'Dôvod výmazu'     				=> 'extract_dovodVymazu',
			'Právna forma'    				=> 'extract_pravnaForma',
			'Predmet činnosti'    			=> 'extract_predmetCinnost',
			'Spoločníci'        			=> 'extract_spolocnici',
			'Výška vkladu'        			=> 'extract_vyskaVkladu',
			'Štatutárny orgán'            	=> 'extract_statutarnyOrgan',
			'Likvidátor'                 	=> 'extract_likvidátori',
			'Likvidácia'                 	=> 'extract_likvidácia',
			'Zastupovanie'                 	=> 'extract_zastupovanie',
			'Konanie menom spoločnosti' 	=> 'extract_konanie',
			'Základné imanie'             	=> 'extract_zakladneImanie',
			'Akcie'                     	=> 'extract_akcie',
			'Dozorná rada'                  => 'extract_dozornaRada',
			'Ďalšie právne skutočnosti'    	=> 'extract_dalsieSkutocnosti',
			'Dátum aktualizácie'        	=> 'extract_datumAktualizacie',
			'Dátum výpisu'                 	=> 'extract_datumVypisu',
		];

		// convert keys to lowercase
		$keys = array_map(function($val){
			return mb_convert_case($val, MB_CASE_LOWER, 'utf-8');
		}, array_keys($tags));

		$tags = array_combine($keys, $tags);

		// convert encoding
		$html = iconv('windows-1250', 'utf-8', $html);
		$html = str_replace('windows-1250', 'utf-8', $html);

		// ensure valid XHTML markup
		$tidy = new \tidy();
		$html = $tidy->repairString($html, array(
			'output-xhtml' => true,
			//'show-body-only' => true, // we MUST have HEAD with charset!!!
		), 'utf8');

		// purify whitespaces
		$html = strtr($html, [
			'&nbsp;' => ' ',
		]);

		$html = preg_replace('/\s+/u', ' ', $html);

		// load XHTML into DOM document
		$xml = new \DOMDocument('1.0', 'utf-8');
		$xml->loadHTML($html);
		$xpath = new \DOMXpath($xml);

		$elements = $xpath->query("/html/body/*"); // all tables /html/body/table

		// loop through elements, parse & normalize data
		if ($elements->length) {

			foreach ($elements as $cntElements => $element) {
				/** @var DOMElement */
				$element;

				// skip first X tables
				if($cntElements < 1){
					continue;
				}

				/** @var DOMNodeList */
				$nodes = $element->childNodes;
				if($nodes->length){
					foreach ($nodes as $node) {
						$firstCol = $xpath->query(".//td[1]", $node); // relative XPATH with ./
						if($firstCol->length){
							$firstCol = $firstCol->item(0)->nodeValue;
							if($firstCol){
								$firstCol = preg_replace('/\s+/u', ' ', $firstCol);
								foreach($tags as $tag => $callback){
									if(false !== mb_stripos($firstCol, $tag, 0, 'utf-8')){
										$secondCol = $xpath->query(".//td[2]", $node);
										if($secondCol->length){
											$secondCol = $secondCol->item(0);
										}
										//$tmp = orsr::{$callback}($firstCol, $secondCol, $xpath);
										$tmp = $this->{$callback}($firstCol, $secondCol, $xpath);
										if($tmp){
											// some sections may return mepty data (e.g. extract_akcie is not aplicable for s.r.o.)
											$this->data = array_merge($this->data, $tmp);
										}
										break; // dont loop any more tags
									}
								}
							}
						}
					}
				}
			}
		}

		// add meta data
		$this->data = ['meta' => [
			'api_version' => self::API_VERSION,
			'sign' => strtoupper(md5(serialize($this->data))),
			'server' => $_SERVER['SERVER_NAME'],
			'time' => date('d.m.Y H:i:s'),
			'sec' => number_format(microtime(true)-$this->ts_start, 3),
			'mb' => number_format(memory_get_usage()/1024/1024, 3),
		]] + $this->data;

		return $this->data;
	}

	################################################################
	### process extracted tags
	################################################################

	protected function extract_prislusnySud($tag, $node, $xpath)
	{
		// e.g. Výpis z Obchodného registra Okresného súdu Banská Bystrica
		$out = ['prislusny_sud' => ''];
		if(false !== mb_stripos($tag, ' súdu ', 0, 'utf-8')){
			list(, $out['prislusny_sud']) = explode(' súdu ', $tag);
		}
		$out = array_map('trim', $out);
		return $out;
	}

	protected function extract_oddiel($tag, $node, $xpath)
	{
		// e.g. Oddiel:  Sro ... Vložka číslo:  8429/S
		$out = [
			'oddiel' => '',
			'vlozka' => '',
			'typ_osoby' => '',
			'hlavicka' => '',
		];
		if(false !== strpos($tag, ':')){
			list(, $out['oddiel']) = explode(':', $tag);
		}
		$val = trim($node->nodeValue);
		if(false !== strpos($val, ':')){
			list(, $out['vlozka']) = explode(':', $val);
		}
		$out = array_map('trim', $out);

		// oddiely - typy subjektov:
		// sa = akciova spolocnost
		// sro = spol. s ruc. obm.
		// sr = komanditna spol.
		//         alebo v.o.s.
		// Pšn = štátny podnik
		//          alebo obecny podnik
		// Po = europska spolocnost
		//         alebo europske druzstvo
		//         alebo organizačná zložka podniku
		//         alebo organizačná zložka zahranicnej osoby
		// Firm = SZCO
		// Dr = druzstvo
		$typ = strtolower(self::stripAccents($out['oddiel']));

		if(preg_match('/(firm)/i', $typ)){
			$out['typ_osoby'] = self::TYP_OSOBY_FYZICKA;
			$out['hlavicka'] = 'Fyzická osoba zapísaná v obchodnom registri Okresného súdu '.$this->data['prislusny_sud'].', vložka '.$out['vlozka'].'.';
			$out['hlavicka_kratka'] = 'OS '.$this->data['prislusny_sud'].', vložka '.$out['vlozka'];
		}else{
			$out['typ_osoby'] = self::TYP_OSOBY_PRAVNICKA;
			if(preg_match('/(dr)/', $typ)){
				$out['hlavicka'] = 'Družstvo zapísané v obchodnom registri Okresného súdu '.$this->data['prislusny_sud'].', vložka '.$out['vlozka'].'.';
				$out['hlavicka_kratka'] = 'OS '.$this->data['prislusny_sud'].', vložka '.$out['vlozka'];
			}elseif(preg_match('/(psn)/', $typ)){
				$out['hlavicka'] = 'Podnik zapísaný v obchodnom registri Okresného súdu '.$this->data['prislusny_sud'].', vložka '.$out['vlozka'].'.';
				$out['hlavicka_kratka'] = 'OS '.$this->data['prislusny_sud'].', vložka '.$out['vlozka'];
			}else{
				$out['hlavicka'] = 'Spoločnosť zapísaná v obchodnom registri Okresného súdu '.$this->data['prislusny_sud'].', oddiel '.$out['oddiel'].', vložka '.$out['vlozka'].'.';
				$out['hlavicka_kratka'] = 'OS '.$this->data['prislusny_sud'].', oddiel '.$out['oddiel'].', vložka '.$out['vlozka'];
			}
		}

		return $out; // e.g. Sro
	}

	protected function extract_obchodneMeno($tag, $node, $xpath)
	{
		$out = self::getFirstTableFirstCell($node, $xpath);

		// e.g. if invalid company name with surrounding double quotes ["Harvex, s.r.o."]
		$map = ['"' => ''];
		$likvidacia = 'nie';
		$outValid = '';
		if(!is_array($out)){
			$out = [$out];
		}

		// meno moze byt array, napr. podnik v likvidacii ma 2 zapisy, druhy s priponou "v likvidacii"
		// vratit chceme prvy zaznam, ktory moze mat priponu "v likvidacii", nepotrebujeme duplikovane zaznamy s rovnakym menom
		foreach($out as $id => $meno){
			$meno = str_replace(array_keys($map), $map, $meno);
			$meno = trim($meno);
			if(false !== mb_stripos($meno, 'v likvidácii')){
				$likvidacia = 'ano';
				$outValid = $meno;
			}
			$out[$id] = $meno;
		}

		if(!$outValid && $out){
			$outValid = $out[0];
		}

		return [
			'obchodne_meno' => $outValid,
			'likvidacia' => $likvidacia,
		];
	}

	protected function extract_sidlo($tag, $node, $xpath)
	{
		$line = self::getFirstTableFirstCellMultiline($node, $xpath);
		$parts = self::line2array($line, ['street', 'city']);
		$out = [];

		// try to extract house number
		if(!empty($parts['street'])){
			$out += self::streetAndNumber($parts['street']);
		}else{
			$out += ['street' => '', 'number' => ''];
		}

		// try to extract city & ZIP
		if(!empty($parts['city'])){
			$out += self::cityAndZip($parts['city']);
		}else{
			$out += ['city' => '', 'zip' => ''];
		}

		if('' == trim(implode($out)) && $line){
			$out['city'] = $line;
		}

		return ['adresa' => $out];
	}

	protected function extract_bydlisko($tag, $node, $xpath)
	{
		// nezavadzame novy element pre adresu, vzdy je to bud sidlo alebo bydlisko
		return self::extract_sidlo($tag, $node, $xpath);
	}

	protected function extract_ico($tag, $node, $xpath)
	{
		$out = self::getFirstTableFirstCell($node, $xpath);
		$out = preg_replace('/\s+/u', '', $out);
		return ['ico' => $out];
	}

	protected function extract_denZapisu($tag, $node, $xpath)
	{
		$out = self::getFirstTableFirstCell($node, $xpath);
		return ['den_zapisu' => $out];
	}

	protected function extract_denVymazu($tag, $node, $xpath)
	{
		$out = self::getFirstTableFirstCell($node, $xpath);
		return ['den_vymazu' => $out];
	}

	protected function extract_dovodVymazu($tag, $node, $xpath)
	{
		$out = self::getFirstTableFirstCell($node, $xpath);
		return ['dovod_vymazu' => $out];
	}

	protected function extract_pravnaForma($tag, $node, $xpath)
	{
		$out = self::getFirstTableFirstCell($node, $xpath);
		return ['pravna_forma' => $out];
	}

	protected function extract_predmetCinnost($tag, $node, $xpath)
	{
		$out = self::getFirstTableFirstCell($node, $xpath, true);
		return ['predmet_cinnosti' => $out];
	}

	protected function extract_spolocnici($tag, $node, $xpath)
	{
		$out = [];
		$organy = $xpath->query(".//table", $node);
		if($organy->length){
			foreach($organy as $organ){
				$out[] = self::getFirstTableFirstCellMultiline($organ, $xpath, ".//tr/td[1]/*");
			}
		}
		return ['spolocnici' => $out];
	}

	protected function extract_vyskaVkladu($tag, $node, $xpath)
	{
		$out = [];
		$organy = $xpath->query(".//table", $node);
		if($organy->length){
			foreach($organy as $organ){
				$tmp = self::getFirstTableFirstCellMultiline($organ, $xpath, ".//tr/td[1]/*");
				$out[] = str_replace(' Splaten', ', Splaten', $tmp); // fix "Ing. Tibor Rauch,  Vklad: 200 000 Sk Splatené: 200 000 Sk"
			}
		}
		return ['vyska_vkladu' => $out];
	}

	protected function extract_statutarnyOrgan($tag, $node, $xpath)
	{
		$out = [];
		$organy = $xpath->query(".//table", $node);
		if($organy->length){
			$type = '';
			foreach($organy as $organ){
				// struktura (vsetko <tables> elementy pre row):
				// konatel
				// meno 1, priezvisko 1, adresa
				// meno 2, priezvisko 2, adresa
				// spolocnici
				// meno 1, priezvisko 1, adresa
				// meno 2, priezvisko 2, adresa
				$text = self::getFirstTableFirstCellMultiline($organ, $xpath, ".//tr/td[1]/*");

				if(!$type && false !== strpos($text, '-')){
					// add item, e.g. "Ing. Jozef Klein , CSc. - podpredseda predstavenstva"
					// niekedy nemusi byt nazov "predstavenstvo" alebo "dozorna rada", ale mozu byt vymenovani clenovia s uvedenim funkcie, e.g. "Ing. Vladislav Šustr - predseda predstavenstva "
					$out[] = $text;
				}elseif(false === strpos($text, ',')){
					// switch the key
					$type = $text;
					$out[$type] = [];
				}else if($type){
					// add item & parse row - pozor na poradie, niekedy je uvedena len obec (pod menom)
					$parts = self::line2array($text, ['name', 'city', 'street', 'since']);

					if(!empty($parts['since']) && false !== strpos($parts['since'], ':')){
						list(, $parts['since']) = explode(':', $parts['since']);
						$parts['since'] = trim($parts['since']);
					}

					$tmp = empty($parts['name']) ? [] : self::line2array($parts['name'], ['name', 'function'], '-');
					if(!empty($tmp['function'])){
						$parts['name'] = $tmp['name'];
						$parts['function'] = $tmp['function'];
					}

					$tmp = empty($parts['street']) ? [] : self::streetAndNumber($parts['street']);
					if(!empty($tmp['number'])){
						$parts['street'] = $tmp['street'];
						$parts['number'] = $tmp['number'];
					}

					$tmp = empty($parts['city']) ? [] : self::cityAndZip($parts['city']);
					if(!empty($tmp['zip'])){
						$parts['zip'] = $tmp['zip'];
						$parts['city'] = $tmp['city'];
					}

					ksort($parts);
					$out[$type][] = $parts;
				}
			}
		}
		return ['statutarny_organ' => $out];
	}

	protected function extract_likvidátori($tag, $node, $xpath)
	{
		$out = self::getFirstTableFirstCellMultiline($node, $xpath);
		if($out){

			$parts = self::line2array($out, ['name', 'street', 'city', 'since']);

			if(!empty($parts['since']) && false !== strpos($parts['since'], ':')){
				list(, $parts['since']) = explode(':', $parts['since']);
				$parts['since'] = trim($parts['since']);
			}

			$tmp = self::streetAndNumber($parts['street']);
			if(!empty($tmp['number'])){
				$parts['street'] = $tmp['street'];
				$parts['number'] = $tmp['number'];
			}

			$tmp = self::cityAndZip($parts['city']);
			if(!empty($tmp['zip'])){
				$parts['zip'] = $tmp['zip'];
				$parts['city'] = $tmp['city'];
			}

			ksort($parts);
			$out = $parts;
			return ['likvidatori' => $out];
		}
	}

	protected function extract_likvidácia($tag, $node, $xpath)
	{
		$out = self::getFirstTableFirstCell($node, $xpath);
		return ['likvidacia' => $out];
	}

	protected function extract_zastupovanie($tag, $node, $xpath)
	{
		$out = self::getFirstTableFirstCell($node, $xpath);
		return ['zastupovanie' => $out];
	}

	protected function extract_konanie($tag, $node, $xpath)
	{
		$out = self::getFirstTableFirstCell($node, $xpath);
		return ['konanie_menom_spolocnosti' => $out];
	}

	protected function extract_zakladneImanie($tag, $node, $xpath)
	{
		$out = self::getFirstTableFirstCell($node, $xpath);
		$out = str_replace(' Rozsah', ', Rozsah', $out); // fix "6 972 EUR Rozsah splatenia: 6 972 EUR"
		$out = self::trimSpaceInNumber($out);
		return ['zakladne_imanie' => $out];
	}

	protected function extract_akcie($tag, $node, $xpath)
	{
		$out = [];

		$akcie = $xpath->query(".//table", $node);
		if($akcie->length){
			foreach($akcie as $akcia){
				$tmp = self::getFirstTableFirstCellMultiline($akcia, $xpath, ".//tr/td[1]/*");
				if($tmp && false !== strpos($tmp, ',')){
					$data = [];
					$items = explode(',', $tmp);
					foreach($items as $item){
						if(false !== strpos($item, ':')){
							list($key, $val) = explode(':', $item);
							$key = trim(strtolower(self::stripAccents($key)));
							$key = str_replace(' ', '_', $key);
							if($key == 'pocet' || $key == 'menovita_hodnota'){
								$val = self::trimSpaceInNumber($val);
							}
							$data[$key] = trim($val);
						}
					}
					$out[] = $data;
				}
			}
		}

		if($out){
			return ['akcie' => $out];
		}
	}

	protected function extract_dozornaRada($tag, $node, $xpath)
	{
		$out = [];
		$rada = $xpath->query(".//table", $node);
		if($rada->length){
			foreach($rada as $person){
				$line = self::getFirstTableFirstCellMultiline($person, $xpath, ".//tr/td[1]/*");

				if(substr_count($line, ',') >= 4){
					$parts = self::line2array($line, ['name', 'street', 'city', 'country', 'since']);
				}else{
					$parts = self::line2array($line, ['name', 'street', 'city', 'since']);
					$parts['country'] = 'Slovenská republika';
				}

				if(!empty($parts['since']) && false !== strpos($parts['since'], ':')){
					list(, $parts['since']) = explode(':', $parts['since']);
					$parts['since'] = trim($parts['since']);
				}

				$tmp = self::line2array($parts['name'], ['name', 'function'], '-');
				if(!empty($tmp['function'])){
					$parts['name'] = $tmp['name'];
					$parts['function'] = $tmp['function'];
				}else{
					$parts['function'] = '';
				}

				$tmp = self::streetAndNumber($parts['street']);
				if(!empty($tmp['number'])){
					$parts['street'] = $tmp['street'];
					$parts['number'] = $tmp['number'];
				}else{
					$parts['number'] = '';
				}

				$tmp = self::cityAndZip($parts['city']);
				if(!empty($tmp['zip'])){
					$parts['zip'] = $tmp['zip'];
					$parts['city'] = $tmp['city'];
				}else{
					$parts['zip'] = '';
				}

				ksort($parts);
				$out[] = $parts;
			}
		}

		if($out){
			return ['dozorna_rada' => $out];
		}
	}

	protected function extract_dalsieSkutocnosti($tag, $node, $xpath)
	{
		$out = self::getFirstTableFirstCell($node, $xpath);
		return ['dalsie_skutocnosti' => $out];
	}

	protected function extract_datumAktualizacie($tag, $node, $xpath)
	{
		$out = self::getFirstTableFirstCell($node, $xpath, false, ".//../td[2]");
		return ['datum_aktualizacie' => $out];
	}

	protected function extract_datumVypisu($tag, $node, $xpath)
	{
		$out = self::getFirstTableFirstCell($node, $xpath, false, ".//../../tr[2]/td[2]");
		return ['datum_vypisu' => $out];
	}

	################################################################
	### auxiliary methods
	################################################################

	/**
	* Return value of second column
	* Multiple tables can reuse Xpath pattern.
	* @param DOMElement $node
	* @param DOMXPath $xpathObject
	* @param bool|string $returnArray [true|false|auto] If FALSE, return string, if AUTO return string if only 1 item, otherwise return array
	* @param string $xpath Extracted XPATH, default ".//table/tr/td[1]"
	*/
	protected static function getFirstTableFirstCell($node, $xpathObject, $returnArray = 'auto', $xpath = ".//table/tr/td[1]")
	{
		$out = [];
		$subNodes = $xpathObject->query($xpath, $node);
		if($subNodes->length){
			foreach($subNodes as $subNode){
				$out[] = trim($subNode->nodeValue);
			}
		}
		if(strtolower($returnArray) == 'auto'){
			if(count($out) <= 1){
				$out = trim(implode(' ', $out));
			}
		}elseif(!$returnArray){
			$out = trim(implode(" \n", $out));
		}
		return $out;
	}

	/**
	* Return value of second column with multilines separated by comma
	* @param DOMElement $node
	* @param DOMXPath $xpathObject
	* @param string $xpath Extracted XPATH, default ".//table/tr/td[1]/*"
	*/
	protected static function getFirstTableFirstCellMultiline($node, $xpathObject, $xpath = ".//table/tr/td[1]/*")
	{
		$out = '';

		/** @var DOMNodeList */
		$subNodes = $xpathObject->query($xpath, $node);

		if($subNodes->length){

			foreach($subNodes as $subNode){
				/** @var DOMElement */
				$subNode;
				$tmp = trim($subNode->nodeValue);
				$tmp = str_replace(',', '', $tmp);
				$out .= ($tmp == '') ? ', ' : ' '.$tmp;
			}

		}

		return trim($out, " ,\t\n");
	}

	/**
	* fix "6 972 989" -> "6972989"
	* @param string $number
	*/
	protected static function trimSpaceInNumber($number){

		$out = $number;

		if(preg_match('/([\d ]*)/', $out, $matches)){ // fix "6 972 989" -> "6972989"

			$map = [];

			foreach($matches as $match){
				$map[trim($match)] = trim(str_replace(' ', '', $match));
			}

			$out = strtr($out, $map);
		}

		return $out;
	}

	/**
	* Strip accented characters
	* @param string $str
	* @param bool $stripExtra If true, all non human charcters will be removed, e.g. -, /, etc
	*/
	protected static function stripAccents($str, $stripExtra = true)
	{
		$map = array(
			// spoluhlasky / accented consonants
			"š" => "s", // s
			"Š" => "S", // S
			"ž" => "z", // z
			"Ž" => "Z", // Z
			"ť" => "t", // t
			"Ť" => "T", // T
			"ľ" => "l", // l
			"Ľ" => "L", // L
			"Č" => "C", // C
			"č" => "c", // c
			"Ŕ" => "R", // R
			"ŕ" => "r", // r
			"ň" => "n", // n
			"Ň" => "N", // N
			"ď" => "d", // u
			"Ď" => "D", // U

			// samohlasky / accented vowels
			"á" => "a",
			"Á" => "A",
			"ä" => "a",
			"Ä" => "A",
			"é" => "e",
			"É" => "E",
			"í" => "i",
			"Í" => "I",
			"ó" => "o",
			"Ó" => "O",
			"ô" => "o",
			"Ô" => "O",
			"ú" => "u",
			"Ú" => "U",
			"ý" => "y",
			"Ý" => "Y",
		);

		$str = str_replace( array_keys($map), array_values($map), $str);
		return $stripExtra ? preg_replace('/[^a-zA-Z0-9\-_ ]/','',$str) : $str;
	}

	/**
	* Explode supplied line into partial string and map them into supplied keys
	* @param string $line String to explode
	* @param array $keys Mapped keys
	* @param array $separator Default comma [,]
	*/
	protected static function line2array($line, $keys, $separator = ',')
	{
		$out = [];
		$values = explode($separator, $line);

		while($keys && $values){
			$key = trim(array_shift($keys));
			$value = trim(array_shift($values));
			$out[$key] = $value;
		}

		return $out;
	}

	/**
	* Extract zip from city
	* @param string $city e.g. Bratislava 851 05 will return zip = 851 05
	*/
	protected static function cityAndZip($city)
	{
		$out = [
			'city' => $city,
			'zip' => '',
		];

		if(preg_match('/([^\d]+)( [\d ]+)/', $city, $match)){
			$out['city'] = trim($match[1]);
			$out['zip'] = preg_replace('/\s/u','', $match[2]); // remove inline whitespaces
		}

		return $out;
	}

	/**
	* Extract house number from street
	* @param string $street e.g. Nejaká ulica 654/ 99-87B will extract "654/ 9987B" as a house number
	*/
	protected static function streetAndNumber($street)
	{
		$out = [
			'street' => $street,
			'number' => '',
		];

		if(preg_match('/^([\d][\w]) (.+)/', $street, $match)){
			$out['street'] = trim($match[2]);
			$out['number'] = trim($match[1]);
		}elseif(preg_match('/(.+)( [\d \/\-\w]+)/', $street, $match)){
			$out['street'] = trim($match[1]);
			$out['number'] = trim($match[2]);
		}elseif(!preg_match('/([\d]+)/', $street, $match)){
			// no number included, only place name, e.g. Belusa
			$out['street'] = '';
			$out['number'] = '';
		}elseif(preg_match('/([\d \/\-\w]+)/', $street, $match)){
			// only house number
			$out['street'] = '';
			$out['number'] = trim($match[0]);
		}

		$out['street'] = trim(str_replace('č.', '', $out['street']));

		return $out;
	}
}


class _Array2XML
{
	private static $xml = null;
	private static $encoding = 'UTF-8';

	/**
	* Convert array to XML
	* @param string $root Root element name
	* @param [] $array
	*/
	public static function get(array $array = [], $root = 'root')
	{
		$array = _Array2XML::createXML($root, $array);
		return $array->saveXML();
	}

	/**
	 * Initialize the root XML node [optional]
	 * @param $version
	 * @param $encoding
	 * @param $format_output
	 */
	public static function init($version = '1.0', $encoding = 'UTF-8', $format_output = true)
	{
		self::$xml = new \DomDocument($version, $encoding);
		self::$xml->formatOutput = $format_output;
		self::$encoding = $encoding;
	}

	/**
	 * Convert an Array to XML
	 * @param string $node_name - name of the root node to be converted
	 * @param array $arr - aray to be converterd
	 * @return DomDocument
	 */
	protected static function &createXML($node_name, $arr=array())
	{
		$xml = self::getXMLRoot();
		$xml->appendChild(self::convert($node_name, $arr));

		self::$xml = null;    // clear the xml node in the class for 2nd time use.
		return $xml;
	}

	/**
	 * Convert an Array to XML
	 * @param string $node_name - name of the root node to be converted
	 * @param array $arr - aray to be converterd
	 * @return DOMNode
	 */
	private static function &convert($node_name, $arr=array())
	{
		$xml = self::getXMLRoot();
		$node = $xml->createElement($node_name);

		if(is_array($arr)){
			// get the attributes first
			if(isset($arr['@attributes'])) {
				foreach($arr['@attributes'] as $key => $value) {
					if(!self::isValidTagName($key)) {
						throw new \Exception('[_Array2XML] Illegal character in attribute name. attribute: '.$key.' in node: '.$node_name);
					}
					$node->setAttribute($key, self::bool2str($value));
				}
				unset($arr['@attributes']); //remove the key from the array once done.
			}

			// check if it has a value stored in @value, if yes store the value and return
			// else check if its directly stored as string
			if(isset($arr['@value'])) {
				$node->appendChild($xml->createTextNode(self::bool2str($arr['@value'])));
				unset($arr['@value']);    //remove the key from the array once done.
				//return from recursion, as a note with value cannot have child nodes.
				return $node;
			} else if(isset($arr['@cdata'])) {
				$node->appendChild($xml->createCDATASection(self::bool2str($arr['@cdata'])));
				unset($arr['@cdata']);    //remove the key from the array once done.
				//return from recursion, as a note with cdata cannot have child nodes.
				return $node;
			}
		}

		//create subnodes using recursion
		if(is_array($arr)){
			// recurse to get the node for that key
			foreach($arr as $key=>$value){
				if(!self::isValidTagName($key)) {
					throw new \Exception('[_Array2XML] Illegal character in tag name. tag: '.$key.' in node: '.$node_name);
				}
				if(is_array($value) && is_numeric(key($value))) {
					// MORE THAN ONE NODE OF ITS KIND;
					// if the new array is numeric index, means it is array of nodes of the same kind
					// it should follow the parent key name
					foreach($value as $k=>$v){
						$node->appendChild(self::convert($key, $v));
					}
				} else {
					// ONLY ONE NODE OF ITS KIND
					$node->appendChild(self::convert($key, $value));
				}
				unset($arr[$key]); //remove the key from the array once done.
			}
		}

		// after we are done with all the keys in the array (if it is one)
		// we check if it has any text value, if yes, append it.
		if(!is_array($arr)) {
			$node->appendChild($xml->createTextNode(self::bool2str($arr)));
		}

		return $node;
	}

	/*
	 * Get the root XML node, if there isn't one, create it.
	 */
	private static function getXMLRoot()
	{
		if(empty(self::$xml)) {
			self::init();
		}

		return self::$xml;
	}

	/*
	 * Get string representation of boolean value
	 */
	private static function bool2str($v)
	{
		//convert boolean to text value.
		$v = $v === true ? 'true' : $v;
		$v = $v === false ? 'false' : $v;
		return $v;
	}

	/*
	 * Check if the tag name or attribute name contains illegal characters
	 * Ref: http://www.w3.org/TR/xml/#sec-common-syn
	 */
	private static function isValidTagName($tag)
	{
		$pattern = '/^[a-z_]+[a-z0-9\:\-\.\_]*[^:]*$/i';
		return preg_match($pattern, $tag, $matches) && $matches[0] == $tag;
	}

}

#######################################################
