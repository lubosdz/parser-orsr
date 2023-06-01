<?php
/**
* Tests for Slovak Rebublic Business Directory Parser - ORSR.sk - Obchodny register SR
* Tests passing PHP 5.6 - 8.2.3 as of 06/2023
*/

use lubosdz\parserOrsr\ConnectorOrsr;
use PHPUnit\Framework\TestCase;

class ConnectorOrsrTest extends TestCase
{
	// short delay 0.5 sec after request
	const DELAY_MSEC = 500000;

	public function testSearchCompanies()
	{
		$connector = new ConnectorOrsr;

		/**
		Sample response : array =
			MATADOR Automation, s. r. o.: string = "vypis.asp?ID=361195&SID=6&P=0"
			MATADOR Automotive Vráble, a.s.: string = "vypis.asp?ID=1319&SID=9&P=0"
			MATADOR Design, s. r. o.: string = "vypis.asp?ID=320429&SID=4&P=0"
			MATADOR HOLDING, a.s.: string = "vypis.asp?ID=427252&SID=2&P=0"
			MATADOR Industries, a. s.: string = "vypis.asp?ID=5962&SID=6&P=0"
		*/
		$data = $connector->findByObchodneMeno('Matador');
		$this->assertTrue($data && is_array($data) && false !== stripos(key($data), 'Matador'));
		usleep(self::DELAY_MSEC);
		$connector->resetOutput();

		/**
		Sample response: : array =
			Peter Novák ( Agropecompany s.r.o. ): string = "vypis.asp?ID=329192&SID=2&P=0"
			Peter Novák ( BAILEY s. r. o. ): string = "vypis.asp?ID=540821&SID=6&P=0"
			Peter Novák ( BUDDIES, s. r. o. ): string = "vypis.asp?ID=511164&SID=2&P=0"
			Peter Novák ( CARPS, s. r. o. ): string = "vypis.asp?ID=496247&SID=8&P=0"
			Peter Novák ( Cinq s.r.o. ): string = "vypis.asp?ID=603517&SID=7&P=0"
			Peter Novák ( DION, s.r.o. ): string = "vypis.asp?ID=104461&SID=6&P=0"
		*/
		$data = $connector->findByPriezviskoMeno('novák', 'peter');
		$this->assertTrue($data && is_array($data) && false !== mb_stripos(key($data), 'novák', 0, 'utf-8') && false !== stripos(key($data), 'peter'));
		usleep(self::DELAY_MSEC);
		$connector->resetOutput();

		/**
		Sample response: : array =
			MATADOR HOLDING, a.s.: string = "vypis.asp?ID=427252&SID=2&P=0"
		*/
		$data = $connector->findByICO('36 294 268'); // ICO = 8 digits, autostrip spaces
		$this->assertTrue($data && is_array($data) && false !== stripos(key($data), 'Matador'));
		usleep(self::DELAY_MSEC);
		$connector->resetOutput();
	}

	public function testFindSingleCompany()
	{
		$connector = new ConnectorOrsr;

		// use data from matching list of links, e.g. "vypis.asp?ID=1319&SID=9&P=0"
		/*
		Sample response:
		-------------
		: array =
		  meta: array =
			api_version: string = "1.0.6"
			sign: string = "A7F8E05DF0E56B5DB5D22ED2FBD7EFC9"
			server: string = ""
			time: string = "06.01.2022 14:07:40"
			sec: string = "3.180"
			mb: string = "8.639"
		  prislusny_sud: string = "Nitra"
		  oddiel: string = "Sa"
		  vlozka: string = "62/N"
		  typ_osoby: string = "pravnicka"
		  hlavicka: string = "Spoločnosť zapísaná v obchodnom registri Okresného súdu Nitra, oddiel Sa, vložka 62/N."
		  hlavicka_kratka: string = "OS Nitra, oddiel Sa, vložka 62/N"
		  obchodne_meno: string = "AGROSTAV, a.s. v likvidácii"
		  likvidacia: array =
			0: string = "Dátum vstupu do likvidácie: 31.5.2013"
			1: string = "Ing. Štefan Uhlár, 1. mája 270/1407, Prašice 956 22, Vznik funkcie: 01.06.2013 Skončenie funkcie: 30.01.2016"
			2: string = "Spôsob konania likvidátora v mene spoločnosti: Likvidátor vykonáva úkony v mene spoločnosti samostatne. Pri právnych úkonoch vykonaných v písomnej forme pripojí likvidátor k obchodnému menu spoločnosti svoj podpis."
		  adresa: array =
			street: string = "Pod Kalváriou"
			number: string = "373"
			city: string = "Topoľčany"
			zip: string = "95501"
		  ico: string = "00 205 940"
			....
		*/
		$data = $connector->getDetailById(1366, 9);
		$this->assertTrue(!empty($data['obchodne_meno']) && false !== stripos($data['obchodne_meno'], 'Agrostav'));

		// since 01/06/2023 (1.0.9) - check typ sudu
		$this->assertTrue(!empty($data['typ_sudu']) && ConnectorOrsr::TYP_SUDU_OKRESNY == $data['typ_sudu']);

		usleep(self::DELAY_MSEC);
		$connector->resetOutput();

		// find company by company name
		$data = $connector->getDetailByICO('1234567'); // fails - must be 8 digits
		$this->assertTrue(empty($data));
		$connector->resetOutput();

		$data = $connector->getDetailByICO('36 294 268');
		$this->assertTrue(!empty($data['obchodne_meno']) && false !== stripos($data['obchodne_meno'], 'Matador'));

		// since 01/06/2023 (1.0.9) - check typ sudu
		$this->assertTrue(!empty($data['typ_sudu']) && ConnectorOrsr::TYP_SUDU_MESTSKY == $data['typ_sudu']);
		usleep(self::DELAY_MSEC);
	}

}
