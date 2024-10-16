<?php
/**
* Parser pre vypis z obchodneho registra SR
* Lookup service for Slovak commercial register (www.orsr.sk)
*
* Version 1.1.1 (released 16.10.2024)
* (c) 2015 - 2024 lubosdz@gmail.com
*
* ------------------------------------------------------------------
* Disclaimer / Prehlásenie:
* Kód poskytnutý je bez záruky a môže kedykoľvek prestať fungovať.
* Jeho funkčnosť je striktne naviazaná na generovanú štruktúru HTML elementov.
* Autor nie je povinný udržiavať kód aktuálny a funkčný, ani neposkytuje ku nemu žiadnu podporu.
* Kód bol sprístupnený na základe početných žiadostí vývojárov finančno-ekonomických aplikácií
* a neschopnosti štátnych inštitúcií poskytnúť kvalitné a zjednotené údaje o podnikateľských subjektoch.
* Autor nezodpovedá za nesprávne použitie kódu.
* ------------------------------------------------------------------
* Poznámka / Note:
* Obchodný register SR obsahuje len časť subjektov v podnikateľskom prostredí (cca 480 tis.).
* Neobsahuje údaje napr. o živnostníkoch alebo neziskových organizáciách.
* Tieto sa nachádzajú v ďalších verejne prístupných databázach (živnostenský register,
* register účtovných závierok, register právnických osôb). Pokiaľ hľadáte profesionálne
* riešenie s prístupom ku všetkých 1.7 mil. subjektom pozrite projekt https://bizdata.sk.
* ------------------------------------------------------------------
* Github repo:
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
* DOM XML parser class for Slovak Business Register (Business Directory of Slovak Republic)
*/
class ConnectorOrsr
{
    const API_VERSION = '1.1.1';

    /** @var string Endpoint URL */
    const URL_BASE = 'https://www.orsr.sk';

    /** @var float Fixed exchange rate SKK/EUR since 2009 */
    const EXCH_RATE_SKK_EUR = 30.126;

    /** @var string Regex date pattern which accepts "1.2.2024" or "1. 2. 2024" or "01.02.2024" */
    const REGEX_DATE = '(\d{1,2}\. ?\d{1,2}\. ?\d{4})';

    const
        // person types
        TYP_OSOBY_PRAVNICKA = 'pravnicka',
        TYP_OSOBY_FYZICKA = 'fyzicka';

    const
        // court type - since 01/06/2023
        TYP_SUDU_OKRESNY = 'OS', // default court "Okresny sud", valid also before 01/06/2023
        TYP_SUDU_MESTSKY = 'MS'; // court in major cities (Bratislava, Kosice) marked as "Mestský súd", since 01/06/2023

    #################################################
    ##  Configurable public props
    #################################################

    /** @var bool Stores some data into local files to avoid multiple requests during development */
    public $debug = false;

    /** @var string Path to cache directory in debug mode (add trailing slash) */
    public $dirCache = './';

    /** @var bool If false, make php tidy extension optional. Definitely NOT recommended, but for some hostings the only way to go. */
    public $useTidy = true;

    /** @var bool If false, return empty results on error (looks like no matches found and user won't see error message) */
    public $showXmlErrors = true;

    /** @var int The number of seconds to cut off request if server not responding, default 60 as per [default_socket_timeout] */
    public $urlTimeoutSecs = 5;

    /** @var int The number of milliseconds between two consecutive requests to ORSR to prevent rate limit ban */
    public $msecDelayFetchUrl = 500;

    /** @var int After how many requests to ORSR should apply delay to prevent rate limit ban */
    public $delayAfterRequestCount = 3;

    #################################################

    /** @var integer Execution start time */
    protected $ts_start;

    /** @var null|string Output format JSON|XML|RAW */
    protected $format = '';

    /** @var array Extracted data */
    protected $data = [];

    /** @var bool Semaphore to avoid double output, e.g. if matched 1 item (DIC, ICO) we instantly return detail */
    protected $outputSent = false;

    /**
    * Constructor - verify required extensions are loaded
    */
    public function __construct()
    {
        $required = ['mbstring', 'iconv', 'dom', 'json'];
        if($this->useTidy){
            array_push($required, 'tidy');
        }

        foreach ( $required as $extension ) {
            if ( !extension_loaded($extension) ) {
                throw new \Exception('Missing required PHP extension ['.$extension.'].');
            }
        }

        $this->delayAfterRequestCount = max(0, intval($this->delayAfterRequestCount));

        $this->urlTimeoutSecs = intval($this->urlTimeoutSecs);
        if ($this->urlTimeoutSecs < 0 || $this->urlTimeoutSecs > 60) {
            $this->urlTimeoutSecs = 5; // default 5 secs
        }

        $this->ts_start = microtime(true);
    }

    /**
    * Separate method to control timeout when fetching from remote server
    * @param string $url URL to be fetched from
    */
    protected function loadUrl($url)
    {
        if ($this->delayAfterRequestCount > 0) {

            static $cntRequests = 0;

            if ($cntRequests && 0 == ($cntRequests % $this->delayAfterRequestCount)) {
                // ORSR.sk may reject too frequent requests according to rate limits
                $this->msleep();
            }

            ++$cntRequests;
        }

        $ctx = stream_context_create([
            "http" => [
                "timeout" => $this->urlTimeoutSecs,
            ],
            /*
            // optionally uncomment in development ie. if self-signed certificate
            // https://www.php.net/manual/en/context.ssl.php
            "ssl" => [
                "verify_peer" => false,
                "verify_peer_name" => false,
                "allow_self_signed" => true,
            ]
            */
        ]);

        return file_get_contents($url, false, $ctx);
    }

    /**
    * Delay execution in msec
    * @param int $msec e.g. 500 = 0.5 sec
    */
    protected function msleep($msec = 0)
    {
        if (!$msec) {
            $msec = $this->msecDelayFetchUrl;
        }

        $msec = max(0, intval($msec));

        if ($msec >= 1000) {
            $secs = min(5, intval($msec/1000));
            if ($secs > 0) {
                sleep($secs);
            }
        } elseif ($msec > 0){
            $nanosec = $msec * 1000;
            usleep($nanosec); // values over 1 mil. may not be supported on some systems
        }
    }

    /**
    * Clear previosuly extracted data & semaphore that output has been already sent
    * Use e.g. to re-send request with the same instance
    */
    public function resetOutput()
    {
        $this->outputSent = false;
        $this->data = [];
    }

    /**
    * Set output format
    * @param string $format e.g. json|xml or empty string (default, raw output)
    * @return ConnectorOrsr
    */
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
    * @param array $data Data from requested service, which possibly will not contain ALL available attributes
    * @param array $force List of required attributes. Additional queries will be executed, if required attribute is empty
    */
    public function normalizeData(array $data, array $force = [])
    {
        // flatten array if needed
        if(!empty($data['obchodne_meno'][0])){
            $data['obchodne_meno'] = $data['obchodne_meno'][0];
        }
        if(!empty($data['adresa'][0])){
            $data['adresa'] = $data['adresa'][0];
        }

        // normalize attributes
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
                            // parser ZRSR.SK not implemented yet (the implementation requires live token obtained from a previous request - can YOU extract it? :-)
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
                    echo json_encode($this->data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                    break;
                case 'xml':
                    header("Content-Type: text/xml; charset=UTF-8");
                    echo _Array2XML::get($this->data);
                    break;
                case 'raw':
                    return $this->data;
                default:
                    // direct output
                    if(!headers_sent()){
                        header("Content-Type: text/html; charset=UTF-8");
                        echo '<pre>'.print_r($this->data, true).'</pre>';
                    }else{
                        fwrite(STDOUT, PHP_EOL.print_r($this->data, true).PHP_EOL);
                    }
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
    * @param bool $onlyHtml If true return only fetched HTML, dont parse into attributes
    */
    public function getDetailById($id, $sid, $p = 0, $onlyHtml = false)
    {
        $id = intval($id);
        if($id < 1){
            throw new \Exception('Invalid company ID.');
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
            $html = $this->loadUrl($url);
            if($html && $this->debug){
                file_put_contents($path, $html);
            }
        }

        if(!$html){
            throw new \Exception('Failed loading data.');
        }

        if($onlyHtml){
            return $html;
        }

        $this->data = self::extractDetail($html);

        return $this->getOutput();
    }

    /**
    * Fetch company page from ORSR and return parsed data
    * @param string $link Partial link to fetch, e.g. vypis.asp?ID=54190&SID=7&P=0
    * @param bool $onlyHtml If true return only fetched HTML, dont parse into attributes
    */
    public function getDetailByPartialLink($link, $onlyHtml = false)
    {
        $data = [];

        if(false !== strpos($link, 'vypis.asp?')){
            // ID + SID = jedinecny identifikator subjektu
            // SID (ID sudu dla kraja) = 1 .. 8 different companies :-(
            // P = 1 - uplny, 0 - aktualny
            list(, $link) = explode('asp?', $link);
            parse_str($link, $params);

            if(isset($params['ID'], $params['SID'], $params['P'])){
                $data = $this->getDetailById($params['ID'], $params['SID'], $params['P'], $onlyHtml);
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
            $html = $this->loadUrl($url);
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
            $html = $this->loadUrl($url);
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
    * @param bool $onlyHtml If true return only fetched HTML, dont parse into attributes
    * @return array Company / subject details
    */
    public function getDetailByICO($ico, $onlyHtml = false)
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
            $html = $this->loadUrl($url);
            if($html && $this->debug){
                file_put_contents($path, $html);
            }
        }

        // lookup by ICO always finds max. 1 record
        $links = $this->handleFindResponse($html);

        if ($links && is_array($links)) {
            while ($link = array_shift($links)) {
                $html = $this->getDetailByPartialLink($link, true);
                // preverime, ci existuje viac liniek pre rovnake ICO,
                // platna je linka, kde vypis neobsahuje "spis postupeny z dovodu miestnej neprislusnosti"
                // note: we use single-byte stripos() to avoid unnecessary codepage conversion win-1250 -> utf-8
                if(!$links || false === stripos($html, 'vodu miestnej nepr')){
                    // jedina linka alebo platny spis
                    break;
                }
                // short delay before next request
                $this->msleep();
            }

            if ($onlyHtml) {
                // special cases - e.g. prefetch for later parsing
                return $html;
            }

            // extract structured data
            $this->data = self::extractDetail($html);
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
            $html = $this->loadUrl($url);
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
    public function handleFindResponse($html, $formatter = '')
    {
        $html = iconv('windows-1250', 'utf-8', $html);
        $html = str_replace('windows-1250', 'utf-8', $html);

        // load XHTML into DOM document
        $xml = new \DOMDocument('1.0', 'utf-8');

        // ensure valid XHTML markup
        if ($this->useTidy) {
            $tidy = new \tidy();
            $html = $tidy->repairString($html, array(
                'output-xhtml' => true,
                //'show-body-only' => true, // we MUST have HEAD with charset!!!
            ), 'utf8');
        } else {
            libxml_use_internal_errors(true);
            $xml->preserveWhiteSpace = false;
        }

        if( !$xml->loadHTML($html) ){
            // whoops, parsing error
            $errors = libxml_get_errors();
            libxml_clear_errors();
            if(!$this->showXmlErrors){
                return [];
            }
            if(!$errors && !empty($php_errormsg)){
                $errors = $php_errormsg;
            }
            throw new \Exception('XML Error - failed loading XHTML page into DOM XML parser - corrupted XML structure. Please consider enabling tidy extension.'.($errors ? "\n Found errors:\n".print_r($errors, 1) : ''));
        }

        $xpath = new \DOMXpath($xml);

        $rows = $xpath->query("/html/body/table[3]/tr/td[2]"); // all tables /html/body/table

        // loop through elements, parse & normalize data
        $out = [];
        if ($rows->length) {
            foreach ($rows as $row) {
                if($formatter && method_exists($this, $formatter)){
                    $out += self::{$formatter}($row, $xpath);
                }else{
                    // drop double quotes, nazov firmy moze obsahovat uvodzovky, alebo EOLs (multiline)
                    $label = trim(str_replace(['"', "\r\n"], ['', ' '], $row->nodeValue));
                    $links = $xpath->query(".//../td[3]/div/a", $row);

                    if($links->length){
                        // vrati vzdy 2x linku - prva je na Aktualny vypis (P=0), druha Uplny vypis (P=1) - parsujeme len aktualny
                        $linkAktualny = $links->item(0)->getAttribute('href'); // e.g. "vypis.asp?ID=208887&SID=3&P=0"
                        // fix - dont overwrite existing label (ie. same company name with different ICO)
                        if(!empty($out[$label])){
                            $label .= " (".count($out).")";
                        }
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
        $label1 = $row->nodeValue;
        $label2 = $xpath->query(".//../td[3]", $row);
        $label2 = $label2->item(0)->nodeValue;
        $label = self::trimMulti("{$label1} ({$label2})");

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
            'Výpis z Obchodného registra'   => 'extract_prislusnySud',
            'Oddiel'                        => 'extract_oddiel',
            'Obchodné meno'                 => 'extract_obchodneMeno',
            'Sídlo'                         => 'extract_sidlo',
            'Bydlisko'                      => 'extract_bydlisko',
            'Miesto podnikania'             => 'extract_miesto_podnikania',
            'IČO'                           => 'extract_ico',
            'Deň zápisu'                    => 'extract_denZapisu',
            'Deň výmazu'                    => 'extract_denVymazu',
            'Dôvod výmazu'                  => 'extract_dovodVymazu',
            'Spoločnosť zrušená od'         => 'extract_spolocnostZrusenaOd',
            'Právny dôvod zrušenia'         => 'extract_pravnyDovodZrusenia',
            'Právna forma'                  => 'extract_pravnaForma',
            'Predmet činnosti'              => 'extract_predmetCinnost',
            'Spoločníci'                    => 'extract_spolocnici',
            'Výška vkladu'                  => 'extract_vyskaVkladu',
            'Štatutárny orgán'              => 'extract_statutarnyOrgan',
            'Likvidátor'                    => 'extract_likvidátori',
            'Likvidácia'                    => 'extract_likvidácia',
            'Vyhlásenie konkurzu'           => 'extract_vyhlasenieKonkurzu',
            'Správca konkurznej podstaty'   => 'extract_spravcaKonkurznejPodstaty',
            'Zastupovanie'                  => 'extract_zastupovanie',
            'Vedúci'                        => 'extract_vedúci_org_zlozky',
            'Konanie'                       => 'extract_konanie',
            'Základné imanie'               => 'extract_zakladneImanie',
            'členský vklad'                 => 'extract_zakladnyClenskyVklad',
            'Akcie'                         => 'extract_akcie',
            'Dozorná rada'                  => 'extract_dozornaRada',
            'Kontrolná komisia'             => 'extract_kontrolnaKomisia',
            'Ďalšie právne skutočnosti'     => 'extract_dalsieSkutocnosti',
            'Zlúčenie, splynutie'           => 'extract_zlucenieSplynutie',
            'Právny nástupca'               => 'extract_pravnyNastupca',
            'Dátum aktualizácie'            => 'extract_datumAktualizacie',
            'Dátum výpisu'                  => 'extract_datumVypisu',
        ];

        // convert keys to lowercase
        $keys = array_map(function($val){
            return mb_convert_case($val, MB_CASE_LOWER, 'utf-8');
        }, array_keys($tags));

        $tags = array_combine($keys, $tags);

        // convert encoding
        // fix: added //TRANSLIT//IGNORE - some foreign companies may contain invalid UTF-8 chars
        $html = iconv('windows-1250', 'utf-8//TRANSLIT//IGNORE', $html);
        $html = str_replace('windows-1250', 'utf-8', $html);

        $xml = new \DOMDocument('1.0', 'utf-8');

        // ensure valid XHTML markup
        if ($this->useTidy) {
            $tidy = new \tidy();
            $html = $tidy->repairString($html, array(
                'output-xhtml' => true,
                //'show-body-only' => true, // we MUST have HEAD with charset!!!
            ), 'utf8');
        } else {
            libxml_use_internal_errors(true);
            $xml->preserveWhiteSpace = false;
        }

        // convert &nbsp; entity to a simple whitespace & replace multiple whitespaces to a single whitespace
        $html = strtr($html, ['&nbsp;' => ' ']);
        $html = self::trimMulti($html);

        // load XHTML into DOM document
        if( !$xml->loadHTML($html) ){
            // whoops, parsing error
            $errors = libxml_get_errors();
            libxml_clear_errors();
            if(!$this->showXmlErrors){
                $this->data = [];
                return [];
            }
            if(!$errors && !empty($php_errormsg)){
                $errors = $php_errormsg;
            }
            throw new \Exception('XML Error - failed loading XHTML page into DOM XML parser - corrupted XML structure. Please consider enabling tidy extension.'.($errors ? "\n Found errors:\n".print_r($errors, 1) : ''));
        }

        $xpath = new \DOMXpath($xml);

        $elements = $xpath->query("/html/body/*"); // all tables /html/body/table

        // loop through elements, parse & normalize data
        if ($elements->length) {

            foreach ($elements as $cntElements => $element) {
                /** @var \DOMElement */
                $element;

                // skip first X tables
                if($cntElements < 1){
                    continue;
                }

                /** @var \DOMNodeList */
                $nodes = $element->childNodes;
                if($nodes->length){
                    foreach ($nodes as $node) {
                        $firstCol = $xpath->query(".//td[1]", $node); // relative XPATH with ./
                        if($firstCol->length){
                            $firstCol = $firstCol->item(0)->nodeValue;
                            if($firstCol){
                                $firstCol = self::trimMulti($firstCol);
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
            'server' => empty($_SERVER['SERVER_NAME']) ? '' : $_SERVER['SERVER_NAME'],
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
        // e.g. Výpis z Obchodného registra Okresného súdu Trnava (default)
        // since 01/06/2023 also possible e.g. Výpis z Obchodného registra Mestského súdu Bratislava III
        $out = [
            'typ_sudu' => self::TYP_SUDU_OKRESNY,
            'prislusny_sud' => ''
        ];
        // extract district e.g. Bratislava
        if(false !== mb_stripos($tag, ' súdu ', 0, 'utf-8')){
            list(, $out['prislusny_sud']) = explode(' súdu ', $tag);
        }
        // detect court type - Mestsky or Okresny, we prefer avoiding multibyte comparison and shortest possible non-conflicting string
        if(false !== stripos($tag, ' Mestsk')){
            $out['typ_sudu'] = self::TYP_SUDU_MESTSKY;
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
            'hlavicka_kratka' => '',
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

        $sud_short = (self::TYP_SUDU_MESTSKY == $this->data['typ_sudu']) ? "MS" : "OS";
        $sud_long = (self::TYP_SUDU_MESTSKY == $this->data['typ_sudu']) ? "Mestského súdu" : "Okresného súdu";

        if(preg_match('/(firm)/iu', $typ)){
            $out['typ_osoby'] = self::TYP_OSOBY_FYZICKA;
            $out['hlavicka'] = 'Fyzická osoba zapísaná v obchodnom registri '.$sud_long.' '.$this->data['prislusny_sud'].', oddiel '.$out['oddiel'].', vložka '.$out['vlozka'].'.';
            $out['hlavicka_kratka'] = $sud_short . ' '.$this->data['prislusny_sud'].', oddiel '.$out['oddiel'].', vložka '.$out['vlozka'];
        }else{
            $out['typ_osoby'] = self::TYP_OSOBY_PRAVNICKA;
            if(preg_match('/(dr)/iu', $typ)){
                $out['hlavicka'] = 'Družstvo zapísané v obchodnom registri '.$sud_long.' '.$this->data['prislusny_sud'].', oddiel '.$out['oddiel'].', vložka '.$out['vlozka'].'.';
                $out['hlavicka_kratka'] = $sud_short . ' '.$this->data['prislusny_sud'].', oddiel '.$out['oddiel'].', vložka '.$out['vlozka'];
            }elseif(preg_match('/(psn)/iu', $typ)){
                $out['hlavicka'] = 'Podnik zapísaný v obchodnom registri '.$sud_long.' '.$this->data['prislusny_sud'].', oddiel '.$out['oddiel'].', vložka '.$out['vlozka'].'.';
                $out['hlavicka_kratka'] = $sud_short . ' '.$this->data['prislusny_sud'].', oddiel '.$out['oddiel'].', vložka '.$out['vlozka'];
            }else{
                $out['hlavicka'] = 'Spoločnosť zapísaná v obchodnom registri '.$sud_long.' '.$this->data['prislusny_sud'].', oddiel '.$out['oddiel'].', vložka '.$out['vlozka'].'.';
                $out['hlavicka_kratka'] = $sud_short . ' '.$this->data['prislusny_sud'].', oddiel '.$out['oddiel'].', vložka '.$out['vlozka'];
            }
        }

        return $out;
    }

    protected function extract_obchodneMeno($tag, $node, $xpath)
    {
        $out = self::getFirstTableFirstCell($node, $xpath);

        // e.g. if invalid company name with surrounding double quotes ["Harvex, s.r.o."]
        $map = ['"' => ''];
        $likvidacia = 0; // change since 1.0.7 - we return 0 rather than 'nie'
        $outValid = '';
        if(!is_array($out)){
            $out = [$out];
        }

        // meno moze byt array, napr. podnik v likvidacii ma 2 zapisy, druhy s priponou "v likvidacii"
        // vratit chceme prvy zaznam, ktory moze mat priponu "v likvidacii", nepotrebujeme duplikovane zaznamy s rovnakym menom
        // zaznamy su chronologicky usporiadane - prvy zaznam by mal byt aktualny, pridavok "v likvidacii" je standardna zmena obchodneho mena
        foreach($out as $id => $meno){
            $meno = str_replace(array_keys($map), $map, $meno);
            $meno = trim($meno);
            if(false !== mb_stripos($meno, 'v likvidácii', 0, 'utf-8') || false !== mb_stripos($meno, 'v konkurze', 0, 'utf-8')){
                $likvidacia = 1; // change since 1.0.7 - we return 1 instead of "ano"
                $outValid = $meno;
            }
            $out[$id] = $meno;
        }

        if(!$outValid && $out){
            $outValid = $out[0];
        }

        // change date e.g. 31.12.2020, usable e.g. when company name changed
        $since = self::getEventDate($node, $xpath);

        return [
            'obchodne_meno' => $outValid,
            'obchodne_meno_since' => $since,
            'likvidacia' => $likvidacia,
        ];
    }

    protected function extract_sidlo($tag, $node, $xpath)
    {
        $line = self::getFirstTableFirstCellMultiline($node, $xpath);
        $parts = self::line2array($line, ['street', 'city', 'country']);

        if(!substr_count($line, ',')){
            // e.g. "Poprad" - uvedena len obec
            $parts = self::line2array($line, ['city']);
        }else{
            // typicky "Galvaniho 15/C, Bratislava 821 04"
            $parts = self::line2array($line, ['street', 'city', 'country']);
        }

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

        // country applies only to foreigners
        if(!empty($parts['country'])){
            $out += ['country' => $parts['country']];
        }

        // we only have city, but not street & nr.
        if('' == trim(implode($out)) && $line){
            $out['city'] = $line;
        }

        $out['since'] = self::getEventDate($node, $xpath);

        return ['adresa' => $out];
    }

    protected function extract_bydlisko($tag, $node, $xpath)
    {
        // nezavadzame novy element pre adresu, vzdy je to bud sidlo alebo bydlisko
        return self::extract_sidlo($tag, $node, $xpath);
    }

    protected function extract_miesto_podnikania($tag, $node, $xpath)
    {
        $out = self::extract_sidlo($tag, $node, $xpath);
        return ['miesto_podnikania' => $out['adresa']];
    }

    protected function extract_ico($tag, $node, $xpath)
    {
        $out = self::getFirstTableFirstCell($node, $xpath);
        $out = self::trimAll($out); // fix 05/2021: zacali vkladat medzeru do ICO, povodne "14099608", najdene aj "14 099 608"
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
        if(is_array($out)){
            // moze sa vyskytnut chybny zapis - niektore subjekty maju 2x riadok pod sebou s rovnakym datumom, vtedy vrati pole
            // fix: prevezmeme prvy datum
            if(!empty($out[0]) && preg_match('/'.self::REGEX_DATE.'/u', $out[0], $match)){
                $out = trim($match[0]);
            }else{
                return;
            }
        }
        return ['den_vymazu' => $out];
    }

    protected function extract_dovodVymazu($tag, $node, $xpath)
    {
        $out = self::getFirstTableFirstCell($node, $xpath);
        return ['dovod_vymazu' => $out];
    }

    protected function extract_spolocnostZrusenaOd($tag, $node, $xpath)
    {
        $out = self::getFirstTableFirstCell($node, $xpath);
        return ['spolocnostZrusenaOd' => $out];
    }

    protected function extract_zlucenieSplynutie($tag, $node, $xpath)
    {
        $out = self::getFirstTableFirstCell($node, $xpath);
        return ['zlucenieSplynutie' => $out];
    }

    protected function extract_pravnyDovodZrusenia($tag, $node, $xpath)
    {
        $out = self::getFirstTableFirstCell($node, $xpath);
        return ['pravnyDovodZrusenia' => $out];
    }

    protected function extract_pravnyNastupca($tag, $node, $xpath)
    {
        $out = self::getFirstTableFirstCellMultiline($node, $xpath);
        if($out){

            $parts = self::line2array($out, ['name', 'street', 'city']);

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

            $parts['since'] = self::getEventDate($node, $xpath);

            //ksort($parts);
            $out = $parts;
            return ['pravny_nastupca' => $out];
        }
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
                $text = self::getFirstTableFirstCellMultiline($organ, $xpath, ".//tr/td[1]/*");

                if(substr_count($text, ',') >= 4 && false !== stripos($text, 'repub')){
                    $parts = self::line2array($text, ['function', 'name', 'street', 'city', 'country']);
                }else{
                    $parts = self::line2array($text, ['name', 'street', 'city', 'country']);
                }

                if(preg_match('/(.+)\s*IČO\s*:\s*\d/u', $parts['name'], $match)){
                    $parts['name'] = explode('IČO', $parts['name'])[0];
                    $parts['name'] = trim($parts['name'], ' ,;-:');
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

                $parts['since'] = self::getEventDate($node, $xpath);

                $out[] = $parts;
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
                $tmp = trim($tmp, ' ,;-');
                $tmp = preg_replace('/(\d) (\d)/', '$1$2', $tmp); // kill spaces inside sequence of digits, e.g. 4 648 EUR -> 4648 EUR
                $tmp = str_replace(' Splaten', ', Splaten', $tmp); // fix "Ing. Tibor Rauch,  Vklad: 200 000 Sk Splatené: 200 000 Sk"
                $parts = self::line2array($tmp, ['name', 'vklad', 'splatene']);

                // Pavol B o b o k -> Pavol Bobok
                $parts['name'] = self::trimInsideChars($parts['name']);

                if(preg_match('/.+( zast\.)/iu', $parts['name'], $match)){
                    // Nestlé S.A. Nestlé A.G. Nestlé Ltd. zast. Ing. Darinou Matyášovou Sládkovičova 10 Prievidza
                    list($parts['name'], $parts['zastupena']) = explode($match[1], $parts['name']);
                    $parts['name'] = trim($parts['name'], ' ,;-:');
                    $parts['zastupena'] = trim($parts['zastupena'], ' ,;-:');
                }elseif(preg_match('/.+( zapísan[á|ý|é] v|zapísanej v)/iu', $parts['name'], $match)){
                    // HDO Druckguss-und Oberflächentechnik GmbH zapísaná (zapisana|zapisanej..) v obchodnom registri vedenom okresným súdom / Amtsgericht / Paderborn pod číslom HRB 3218
                    // odsekneme len neplatnu cast za menom, ostatne neriesime, extra info za menom je uz parsed napr. v zozname spolocnikov
                    list($parts['name']) = explode($match[1], $parts['name']);
                    $parts['name'] = trim($parts['name'], ' ,;-:');
                }

                if(!empty($parts['vklad'])){
                    if(false !== strpos($parts['vklad'], ':')){
                        list(, $parts['vklad']) = explode(':', $parts['vklad']);
                        $parts['vklad'] = trim($parts['vklad']);
                        // remove "penazny vklad" in "1667 EUR ( peňažný vklad )"
                        if(false !== strpos($parts['vklad'], '(')){
                            $parts['vklad'] = explode('(', $parts['vklad'])[0];
                            $parts['vklad'] = trim($parts['vklad']);
                        }
                    }elseif(false !== strpos($parts['vklad'], '(')){
                        // fix: ICO 50591142 - niekedy je uvedene v zatvorkach "( penazny vklad )", bez sum
                        $parts['popis'] = trim($parts['vklad'], ' (),;.-');
                        unset($parts['vklad']);
                    }
                }

                if(!empty($parts['splatene']) && false !== strpos($parts['splatene'], ':')){
                    list(, $parts['splatene']) = explode(':', $parts['splatene']);
                    $parts['splatene'] = trim($parts['splatene']);
                }

                // zistime menu, prip. urobime prepocet na EUR. Mena moze byt EUR alebo Sk (pre zaniknute spolocnosti pred digitalizaciou)
                if(!empty($parts['vklad']) && preg_match('/([\d\.]+) ([^\d]+)/u', $parts['vklad'], $match)){
                    $orig = $parts['vklad'];
                    $parts['currency'] = trim($match[2]); // meny by mali byt vzdy zhodne - suma aj splatene
                    $parts['vklad'] = round((float)trim($match[1]), 2);
                    if(false !== stripos($parts['currency'], 'Sk')){
                        $parts['vklad'] = round($parts['vklad'] / self::EXCH_RATE_SKK_EUR, 2);
                        $parts['vklad_orig'] = $orig;
                        $parts['currency'] = 'EUR';
                    }
                }

                if(!empty($parts['splatene']) && preg_match('/([\d\.]+) ([^\d]+)/u', $parts['splatene'], $match)){
                    $orig = $parts['splatene'];
                    $parts['currency'] = trim($match[2]); // meny by mali byt vzdy zhodne - suma aj splatene
                    $parts['splatene'] = round((float)trim($match[1]), 2);
                    if(false !== stripos($parts['currency'], 'Sk')){
                        $parts['splatene'] = round($parts['splatene'] / self::EXCH_RATE_SKK_EUR, 2);
                        $parts['splatene_orig'] = $orig;
                        $parts['currency'] = 'EUR';
                    }
                }

                if(empty($parts['splatene'])){
                    unset($parts['splatene']);
                }

                $out[] = $parts;
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
                $text = trim($text, ' ,;-');

                if(!$type && false !== strpos($text, '-')){
                    // add item, e.g. "Ing. Jozef Klein , CSc. - podpredseda predstavenstva"
                    // niekedy nemusi byt nazov "predstavenstvo" alebo "dozorna rada", ale mozu byt vymenovani clenovia s uvedenim funkcie, e.g. "Ing. Vladislav Šustr - predseda predstavenstva"
                    $out[] = $text;
                }elseif(false === strpos($text, ',') && false === strpos($text, '-')){
                    // switch the key - nazov sekcie napr. "predstavenstvo"
                    $type = $text;
                    if(empty($out[$type])){
                        $out[$type] = [];
                    }
                }else if($type){
                    // add item & parse row - pozor na poradie, niekedy je uvedena len obec (pod menom)
                    // typicky: "Ing. Milan Hluzák, M. Benku 21/9, Prievidza 971 01, Vznik funkcie: 04.03.2014"
                    // anomalia: "Jiří Bejšovec - vznik funkcie: 17.01.2002, Zákostelní 5/666, Praha 9, Česká republika"
                    $vznikFunkcie = $zanikFunkcie = $extraInfo = '';

                    // najprv preverime zastupcu
                    if(preg_match('/\b(zastúpen[^:]+|zast.[^:]*):/', $text, $match)){
                        // e.g. "ROLSED spol. s r.o. zast.: Jaroslav Sedlák, - obchodný riaditeľ, Haanova 50, Bratislava 851 04"
                        list($text, $extraInfo) = explode($match[0], $text);
                        $extraInfo = trim($match[0]).' '.trim($extraInfo, ' ,;-');
                        $extraInfo = str_replace(', -', ' -', $extraInfo);
                    }

                    if(preg_match('/Vznik funkcie:\s*'.self::REGEX_DATE.'/iu', $text, $match)){
                        $vznikFunkcie = trim($match[1], ' ,;-');
                        $text = str_replace($match[0], ' ', $text);
                        $text = trim($text, ' ,;-');
                    }

                    if(preg_match('/Skončenie funkcie:\s*'.self::REGEX_DATE.'/iu', $text, $match)){
                        $zanikFunkcie = trim($match[1], ' ,;-');
                        $text = str_replace($match[0], ' ', $text);
                        $text = trim($text, ' ,;-');
                    }

                    if(1 == substr_count($text, ',')){
                        $parts = self::line2array($text, ['name', 'city']);
                    }else{
                        $parts = self::line2array($text, ['name', 'street', 'city', 'country']);
                    }

                    if(preg_match('/\b(nar[^:]+):/iu', $parts['name'], $match)){
                        // fix "PhDr. Mária Liptáková, nar.: 13.8.1961"
                        $parts['name'] = explode($match[1], $parts['name'])[0];
                    }
                    $parts['name'] = trim($parts['name'], ' ,;-:(');

                    // split name - function, e.g. "Ing. Václav Klein - predseda"
                    $tmp = (false === strpos($parts['name'], ' - '))  ? [] : self::line2array($parts['name'], ['name', 'function'], ' - ');
                    if(!empty($tmp['name']) && str_word_count($tmp['name']) > 1){
                        // fix: the name must be at least 2 words, e.g. ignore "Peter - Alfred Wippermann"
                        if(!empty($tmp['function'])){
                            $parts['name'] = $tmp['name'];
                            $parts['function'] = self::trimInsideChars($tmp['function']);
                        }else{
                            $parts['name'] = $tmp['name']; // removed dash e.g. Jiří Bejšovec - vznik funkcie: 17.01.2002
                        }
                    }

                    if(empty($parts['city']) && empty($parts['country']) && !empty($parts['street'])){
                        // adresa bez ulice, len obec
                        $parts['city'] = $parts['street'];
                        $parts['street'] = '';
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

                    if(empty($parts['country'])){
                        unset($parts['country']);
                    }

                    if(!empty($vznikFunkcie)){
                        $parts['vznik_funkcie'] = $vznikFunkcie;
                        // just backwards compatability - deprecated, use "vznik_funkcie"
                        $parts['since'] = $vznikFunkcie;
                    }
                    if(!empty($zanikFunkcie)){
                        $parts['zanik_funkcie'] = $zanikFunkcie;
                    }

                    if($extraInfo){
                        $parts['extraInfo'] = $extraInfo;
                    }

                    // $type = e.g. "konatelia"
                    if(!isset($out[$type])){
                        $out[$type] = [];
                    }

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
            $out = trim($out, ' ,;-');
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

            $out = $parts;
            return ['likvidatori' => $out];
        }
    }

    protected function extract_likvidácia($tag, $node, $xpath)
    {
        $out = self::getFirstTableFirstCell($node, $xpath);
        return ['likvidacia' => $out];
    }

    protected function extract_vyhlasenieKonkurzu($tag, $node, $xpath)
    {
        $txt = self::getFirstTableFirstCell($node, $xpath);
        $out = ['konkurz' => [
            'detail' => $txt,
        ]];
        // zistime datum vyhlasenie konkurzu v "Dátum vyhlásenia konkurzu: 24.2.2012 Uznesením Okresného súdu ..."
        if($out && preg_match('/konkurzu:\s*'.self::REGEX_DATE.'/ui', $txt, $match)){
            $out['konkurz']['since'] = preg_replace('/\s+/u', '', $match[1]); // 10. 10. 2018 -> 10.10.2018
        }
        return $out;
    }

    protected function extract_spravcaKonkurznejPodstaty($tag, $node, $xpath)
    {
        // zhodna struktura atributov
        $out = $this->extract_vedúci_org_zlozky($tag, $node, $xpath);
        return ['spravca_konkurznej_podstaty' => $out['veduci_organizacnej_zlozky']];
    }

    protected function extract_zastupovanie($tag, $node, $xpath)
    {
        // e.g. "Za štátny podnik podpisuje riaditeľ."
        $out = self::getFirstTableFirstCell($node, $xpath);
        return ['zastupovanie' => $out];
    }

    protected function extract_vedúci_org_zlozky($tag, $node, $xpath)
    {
        $out = self::getFirstTableFirstCellMultiline($node, $xpath);
        if($out){
            $out = trim($out, ' ,;-');
            // velmi obtiazny parsing adries - nepredvidatelne texty, napr. "dlhodoby pobyt na uzemi SR", neuvedene plne adresy (niekedy len obec, ziadna ulica), niekedy je uvedena krajina ..
            $parts = self::line2array($out, ['name', 'street', 'city', 'country', 'since'], ',',  '/(pobyt na)/');

            if(!empty($parts['since']) && false !== strpos($parts['since'], ':')){
                // 4 records with country
                list(, $parts['since']) = explode(':', $parts['since']);
                $parts['since'] = $parts['vznik_funkcie'] = trim($parts['since']);
            }elseif(!empty($parts['country']) && false !== strpos($parts['country'], ':')){
                // 3 records without country
                list(, $parts['since']) = explode(':', $parts['country']);
                $parts['since'] = $parts['vznik_funkcie'] = trim($parts['since']);
                unset($parts['country']);
            }

            if(!empty($parts['vznik_funkcie']) && false !== strpos($parts['vznik_funkcie'], ' Skon')){
                list($parts['vznik_funkcie'], $tmp) = explode(' Skon', $parts['vznik_funkcie']);
                $parts['vznik_funkcie'] = trim($parts['vznik_funkcie'], ' ,;.-');
                if(preg_match('/\bfunkcie:\s*'.self::REGEX_DATE.'/iu', $tmp, $match)){
                    $parts['zanik_funkcie'] = trim($match[1], ' ,;.-');
                }
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

            if(empty($parts['country'])){
                unset($parts['country']);
            }

            $out = $parts;
            return ['veduci_organizacnej_zlozky' => $out];
        }
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
        $out = self::trimMulti($out);
        // since 1.0.7 - fix vyska vkladu s desat. miestom napr. Vklad: 331,939189 EUR
        if(preg_match('/\d+,\d+/u', $out, $match)){
            $fixed = str_replace(',', '.', $match[0]);
            $out = str_replace($match[0], $fixed, $out);
        }
        $out = str_replace(' , ', ', ', $out);
        $out = self::line2array($out, ['imanie', 'splatene']);
        if(!empty($out['splatene']) && false !== strpos($out['splatene'], ':')){
            list(, $out['splatene']) = explode(':', $out['splatene']);
            $out['splatene'] = trim($out['splatene']);
        }
        // zistime menu, prip. urobime prepocet na EUR. Mena moze byt EUR alebo Sk (pre zaniknute spolocnosti pred digitalizaciou)
        if(!empty($out['imanie']) && preg_match('/([\d\.]+) ([^\d]+)/u', $out['imanie'], $match)){
            $orig = $out['imanie'];
            $out['currency'] = trim($match[2]); // meny by mali byt vzdy zhodne - imanie aj splatene
            $out['imanie'] = round(trim($match[1]), 2);
            if(false !== stripos($out['currency'], 'Sk')){
                $out['imanie'] = round($out['imanie'] / self::EXCH_RATE_SKK_EUR, 2);
                $out['imanie_orig'] = $orig;
                $out['currency'] = 'EUR';
            }
        }
        if(!empty($out['splatene']) && preg_match('/([\d\.]+) ([^\d]+)/u', $out['splatene'], $match)){
            $orig = $out['splatene'];
            // e.g. "200000 Sk" or "1234.45 EUR"
            $out['currency'] = trim($match[2]);
            $out['splatene'] = round(trim($match[1]), 2);
            if(false !== stripos($out['currency'], 'Sk')){
                $out['splatene'] = round($out['splatene'] / self::EXCH_RATE_SKK_EUR, 2);
                $out['splatene_orig'] = $orig;
                $out['currency'] = 'EUR';
            }
        }
        return ['zakladne_imanie' => $out];
    }

    protected function extract_zakladnyClenskyVklad($tag, $node, $xpath)
    {
        $out = [];
        $vklad = self::getFirstTableFirstCell($node, $xpath);
        if(!is_array($vklad)){
            $vklad = [$vklad];
        }
        foreach($vklad as $row){
            $row = self::trimSpaceInNumber($row);
            $row = self::trimMulti($row);
            if(preg_match('/\d+,\d+/u', $row, $match)){
                $fixed = str_replace(',', '.', $match[0]); // convert to valid numeric format
                $row = str_replace($match[0], $fixed, $row);
            }
            $out[] = $row;
        }
        return ['zakladny_clensky_vklad' => $out];
    }

    protected function extract_akcie($tag, $node, $xpath)
    {
        $out = [];

        $akcie = $xpath->query(".//table", $node);
        if($akcie->length){
            foreach($akcie as $akcia){
                $tmp = self::getFirstTableFirstCellMultiline($akcia, $xpath, ".//tr/td[1]/*");
                $tmp = trim($tmp, ' ,;-');
                if($tmp && false !== strpos($tmp, ',')){
                    $data = [];
                    $items = explode(',', $tmp);
                    foreach($items as $item){
                        if(false !== strpos($item, ':')){
                            list($key, $val) = explode(':', $item);
                            $key = trim(strtolower(self::stripAccents($key)));
                            $key = str_replace(' ', '_', $key);
                            $data[$key] = trim($val);
                            if($key == 'pocet' || $key == 'menovita_hodnota'){
                                $val = self::trimSpaceInNumber($val);
                                if(preg_match('/([\d\.]+) ([^\d]+)/u', $val, $match)){
                                    // e.g. "200000 Sk" or "1234.45 EUR"
                                    $data['currency'] = trim($match[2]);
                                    $data[$key] = round(trim($match[1]), 2);
                                    if(false !== stripos($data['currency'], 'Sk')){
                                        $data[$key] = round($data[$key] / self::EXCH_RATE_SKK_EUR, 2);
                                        $data['currency'] = 'EUR';
                                        $data['orig'] = $val;
                                    }
                                }
                            }
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
                $line = trim($line, ' ,;-');

                if(substr_count($line, ',') >= 4){
                    $parts = self::line2array($line, ['name', 'street', 'city', 'country', 'since']);
                }else{
                    $parts = self::line2array($line, ['name', 'street', 'city', 'since']);
                }

                if(!empty($parts['since'])){
                    // "Vznik funkcie: 30.06.2017"
                    // "Vznik funkcie: 08.04.2004 Skončenie funkcie: 29.05.2012"
                    $tmp = $parts['since'];
                    if(preg_match('/Vznik funkcie:\s*'.self::REGEX_DATE.'/iu', $tmp, $match)){
                        $parts['vznik_funkcie'] = trim($match[1], ' ,;-');
                        $parts['since'] = $parts['vznik_funkcie']; // backwards compatability
                    }
                    if(preg_match('/Skončenie funkcie:\s*'.self::REGEX_DATE.'/iu', $tmp, $match)){
                        $parts['zanik_funkcie'] = trim($match[1], ' ,;-');
                    }
                    // fix "Dkfm. Wolf-Dietger Strobl, Anton Jahn-Gasse 9, Giesshübl 023 72, Rakúsko"
                    if(!preg_match('/\d+/u', $tmp, $match)){
                        // posledna cast moze byt aj krajine, ak neobsahuje cislicu
                        $parts['country'] = trim($tmp, ' .,;-');
                    }
                }

                // fix some strings near the name ..
                if(preg_match('/\b(social[^:]+):/iu', $parts['name'], $match)){
                    // fix "Dr. Cvetko Nikolič, Social Security: 523-90-2845 - predseda"
                    $parts['name'] = explode($match[1], $parts['name'])[0];
                    $parts['name'] = trim($parts['name'], ' ,;-:');
                }

                // e.g. "Ing. Milan Slanina - Člen predstavenstva"
                // pozor - "Dkfm. Wolf-Dietger Strobl", pomlcka moze byt aj v mene
                $tmp = self::line2array($parts['name'], ['name', 'function'], ' - ');
                if(!empty($tmp['function'])){
                    $parts['name'] = $tmp['name'];
                    $parts['function'] = $tmp['function'];
                }else{
                    $parts['function'] = '';
                }

                $tmp = empty($parts['street']) ? '' : self::streetAndNumber($parts['street']);
                if(!empty($tmp['number'])){
                    $parts['street'] = $tmp['street'];
                    $parts['number'] = $tmp['number'];
                }else{
                    $parts['number'] = '';
                }

                $tmp = empty($parts['city']) ? '' : self::cityAndZip($parts['city']);
                if(!empty($tmp['zip'])){
                    $parts['zip'] = $tmp['zip'];
                    $parts['city'] = $tmp['city'];
                }else{
                    $parts['zip'] = '';
                }

                $out[] = $parts;
            }
        }

        if($out){
            return ['dozorna_rada' => $out];
        }
    }

    protected function extract_kontrolnaKomisia($tag, $node, $xpath)
    {
        $out = [];
        $rada = $xpath->query(".//table", $node);
        if($rada->length){
            foreach($rada as $person){
                $line = self::getFirstTableFirstCellMultiline($person, $xpath, ".//tr/td[1]/*");
                $line = trim($line, ' ,;-');

                if(substr_count($line, ',') >= 4){
                    $parts = self::line2array($line, ['name', 'street', 'city', 'country', 'since']);
                }else{
                    $parts = self::line2array($line, ['name', 'street', 'city', 'since']);
                }

                if(false !== strpos($parts['name'], ' - ')){
                    // extract function from "Miroslav Gavorčík - člen kontrolnej funkcie"
                    list($parts['name'], $parts['function']) = explode(' - ', $parts['name']);
                    $parts = array_map('trim', $parts);
                }

                $tmp = empty($parts['street']) ? '' : self::streetAndNumber($parts['street']);
                if(!empty($tmp['number'])){
                    $parts['street'] = $tmp['street'];
                    $parts['number'] = $tmp['number'];
                }else{
                    $parts['number'] = '';
                }

                $tmp = empty($parts['city']) ? '' : self::cityAndZip($parts['city']);
                if(!empty($tmp['zip'])){
                    $parts['zip'] = $tmp['zip'];
                    $parts['city'] = $tmp['city'];
                }else{
                    $parts['zip'] = '';
                }

                if(!empty($parts['since'])){
                    // "Vznik funkcie: 30.06.2017"
                    // "Vznik funkcie: 08.04.2004 Skončenie funkcie: 29.05.2012"
                    $tmp = $parts['since'];
                    if(preg_match('/Vznik funkcie:\s*'.self::REGEX_DATE.'/iu', $tmp, $match)){
                        $parts['vznik_funkcie'] = trim($match[1], ' ,;-');
                    }
                    if(preg_match('/Skončenie funkcie:\s*'.self::REGEX_DATE.'/iu', $tmp, $match)){
                        $parts['zanik_funkcie'] = trim($match[1], ' ,;-');
                    }
                }

                unset($parts['since']);
                $out[] = $parts;
            }
        }

        return ['kontrolna_komisia' => $out];
    }

    protected function extract_dalsieSkutocnosti($tag, $node, $xpath)
    {
        $out = [];

        // extract event dates for each row - nacitame aj druhy stlpec tabulky s datumom
        $rows = self::getFirstTableFirstCell($node, $xpath, true, './/table/tr');

        // since 1.0.7 - extract event dates
        foreach($rows as $row){
            // datum udalosti je vzdy na konci, e.g. "O zmene stanov rozhodlo valné zhromaždenie (od: 24.07.1992)"
            if (preg_match('/ \(od:\s*'.self::REGEX_DATE.'\)$/iu', $row, $match)) {
                $date = trim($match[1]);
                $row = trim(explode(' (od:', $row)[0]);
            }else{
                $date = '';
            }
            $out[] = [
                'eventText' => $row,
                'eventDate' => $date,
            ];
        }

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
    * @param \DOMElement $node
    * @param \DOMXPath $xpathObject
    * @param bool|string $returnArray [true|false|auto] If FALSE, return string, if AUTO return string if only 1 item, otherwise return array
    * @param string $xpath Extracted XPATH, default ".//table/tr/td[1]"
    */
    protected static function getFirstTableFirstCell($node, $xpathObject, $returnArray = 'auto', $xpath = ".//table/tr/td[1]")
    {
        $out = [];
        $subNodes = $xpathObject->query($xpath, $node);
        if($subNodes->length){
            /** @var \DOMElement[] */
            foreach($subNodes as $subNode){
                if($subNode->hasChildNodes()){
                    $tmp = [];
                    foreach($subNode->childNodes as $childNode){
                        // fix multiple lines in a single cell separated by brackets <br>
                        $comma = 'br' == $childNode->nodeName ? ', ' : '';
                        $tmp[] = $comma . trim($childNode->nodeValue);
                    }
                    $tmp = implode(' ', $tmp);
                    $tmp = self::trimMulti($tmp);
                    $tmp = str_replace(' , ', ', ', $tmp);
                    $out[] = trim($tmp, ' ,');
                }else{
                    $out[] = trim($subNode->nodeValue);
                }
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
    * Return item date validity - datum platnosti zaznamu
    * @param \DOMElement $node
    * @param \DOMXPath $xpathObject
    * @param string $xpath Extracted XPATH, default ".//table/tr/td[1]/*"
    */
    protected static function getEventDate($node, $xpathObject, $xpath = ".//table/tr/td[2]")
    {
        $out = '';
        $subNodes = $xpathObject->query($xpath, $node);

        if ($subNodes->length) {
            /** @var \DOMElement[] */
            foreach ($subNodes as $subNode) {
                $txt = trim($subNode->nodeValue);
                if (preg_match('/\(od:\s*'.self::REGEX_DATE.'/iu', $txt, $match)) {
                    $out = $match[1];
                    break;
                }
            }
        }

        return $out;
    }

    /**
    * Return value of second column with multilines separated by comma
    * @param \DOMElement $node
    * @param \DOMXPath $xpathObject
    * @param string $xpath Extracted XPATH, default ".//table/tr/td[1]/*"
    */
    protected static function getFirstTableFirstCellMultiline($node, $xpathObject, $xpath = ".//table/tr/td[1]/*")
    {
        $out = '';

        /** @var \DOMNodeList */
        $subNodes = $xpathObject->query($xpath, $node);

        if($subNodes->length){

            /** @var \DOMElement[] */
            foreach($subNodes as $subNode){
                $tmp = trim($subNode->nodeValue);
                $tmp = self::trimMulti($tmp); // fix multiple whitespaces
                //$tmp = self::trimSpaceInNumber($tmp); // works, but may not be wished for PSC

                // fix since 1.0.7 - normalize numbers with decimals, e.g. vyska vkladu s desat. miestom napr. Vklad: 331,939189 EUR
                if(preg_match('/\d+,\d+/u', $tmp, $match)){
                    $fixed = str_replace(',', '.', $match[0]);
                    $tmp = str_replace($match[0], $fixed, $tmp);
                }

                $tmp = str_replace(',', '', $tmp);
                $out .= ($tmp == '') ? ', ' : ' '.$tmp;
            }

            $out = self::trimMulti($out); // fix multiple whitespaces again :-|
        }

        return trim($out, " ,\t\n");
    }

    /**
    * fix "6 972 989" -> "6972989"
    * @param string $number
    */
    protected static function trimSpaceInNumber($number){

        $out = $number;

        if(preg_match('/([\d ]*)/u', $out, $matches)){ // fix "6 972 989" -> "6972989"
            $map = [];
            foreach($matches as $match){
                $map[trim($match)] = trim(str_replace(' ', '', $match));
            }
            $out = strtr($out, $map);
        }

        return $out;
    }

    /**
    * @param string $txt Replace multiple consecutive whitespaces with a single space
    */
    protected static function trimMulti($txt)
    {
        return trim(preg_replace('/\s+/u', ' ', $txt));
    }

    /**
    * @param string $txt Simple trim for all whitespaces, including between characters
    */
    protected static function trimAll($txt)
    {
        return trim(preg_replace('/\s+/u', '', $txt));
    }

    /**
    * Odstrani medzery medzi pismenami, napr. "p r e d s e d a" => "predseda"
    * @param string $txt
    */
    public static function trimInsideChars($txt)
    {
        $parts = explode(' ', $txt);
        if(count($parts) > 3){
            // potencialny kandidat - preverime, ci su vsetky parts len 1 character
            $fixme = true;
            foreach($parts as $chr){
                if(mb_strlen($chr, 'utf-8') > 1){
                    $fixme = false;
                    break;
                }
            }
            if($fixme){
                $txt = implode('', $parts);
            }
        }
        return trim($txt);
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
            "Ř" => "R", // R
            "ř" => "r", // r
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

            // german, hungarian
            "ß" => "ss",
            "ü" => "u",
            "Ü" => "U",
            "Ö" => "O",
            "ö" => "o",
            "Ő" => "O",
            "ő" => "o",
            "ű" => "u",
            "Ű" => "U",

            // polish
            "Ł" => "L",
            "ł" => "l",
            "Ą" => "A",
            "ą" => "a",
            "ę" => "e",
            "Ę" => "E",
            "Ś" => "S",
            "ś" => "s",
            "Ń" => "n",
            "ń" => "n",
            "ź" => "z",
            "Ź" => "Z",
        );

        $str = str_replace( array_keys($map), array_values($map), $str);
        return $stripExtra ? preg_replace('/[^a-zA-Z0-9\-_ ]/','',$str) : $str;
    }

    /**
    * Explode supplied line into partial string and map them into supplied keys
    * @param string $line String to explode
    * @param array $keys Mapped keys
    * @param string $separator Default comma [,]
    * @param string $skipRegex Skip row if contains ignored string (regular expression)
    */
    protected static function line2array($line, $keys, $separator = ',', $skipRegex = '')
    {
        $out = [];
        $values = explode($separator, $line);

        while($keys && $values){
            $value = trim(array_shift($values));
            if($skipRegex && preg_match($skipRegex, $value)){
                continue;
            }
            $key = trim(array_shift($keys));
            $out[$key] = $value;
        }

        return $out;
    }

    /**
    * Extract zip from city.
    * Foreign addresses may also include the "district" key
    * @param string $city e.g. Bratislava 851 05 will return zip = 851 05
    */
    protected static function cityAndZip($city)
    {
        $out = [
            'city' => $city,
            'zip' => '',
        ];

        if(preg_match('/(.+) (\d\d\d ?\d\d)([^\d]+)?$/u', $city, $match)){
            // extract PSC from "Bratislava 1 811 07"
            $out['city'] = trim($match[1]);
            $out['zip'] = preg_replace('/\s/u', '', $match[2]); // remove inline whitespaces
            if(!empty($match[3])){
                $out['district'] = trim($match[3]);
            }
        }elseif(preg_match('/(.+) ([\d\-]+)$/u', $city, $match)){
            // niektore zahranicne formaty PSC napr. "Szczecin 71-252" (Polsky format ZIP)
            $out['city'] = trim($match[1]);
            $out['zip'] = trim($match[2]);
        }

        return $out;
    }

    /**
    * Extract house number from street
    * @param string $street e.g. Nejaká ulica 654/ 99-87B will extract "654/ 9987B" as a house number
    */
    protected static function streetAndNumber($street)
    {
        $street = trim(str_replace('č.', ' ', $street)); // e.g. č.123 -> 123

        $out = [
            'street' => $street,
            'number' => '',
        ];

        if(preg_match('/^([\d][\w]) (.+)/u', $street, $match)){
            $out['street'] = trim($match[2]);
            $out['number'] = trim($match[1]);
        }elseif(preg_match('/(.+)( [\d \/\-\w]+)/u', $street, $match)){
            // very often. e.g "Galvaniho 15/C" or "Vlčie hrdlo 90" or "Golianova ul."
            $out['street'] = trim($match[1]);
            $out['number'] = trim($match[2]);
            if(strtolower($out['number']) == 'ul'){
                $out['number'] = ''; // zmazeme nezmyselne cislo - vyskytuje sa len zriedkavo, ak je skratka "ul." na konci adresy
            }
        }elseif(!preg_match('/([\d]+)/u', $street, $match)){
            // no number included, only place name, e.g. "Belusa" or "Sládkovičovo"
            $out['street'] = '';
            $out['number'] = '';
        }elseif(preg_match('/([\d \/\-\w]+)/u', $street, $match)){
            // only house number e.g. "123/D"
            $out['street'] = '';
            $out['number'] = trim($match[0]);
        }

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
     * @return \DomDocument
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
     * @return \DOMNode
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
