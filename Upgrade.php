<?php
/**
* Composer upgrade notes
*/

namespace lubosdz;

class Upgrade
{
	public static function notes()
	{
		$notes = <<<EOD
Parser ORSR.sk - Free partial solution
======================================
Parser Obchodného registra SR umožňuje načítavanie údajov o približne 480-tisíc
obchodných spoločnostiach. Neumožňuje načítavanie údajov napr. o živnostníkoch
alebo neziskových organizáciách, nakoľko nie sú obsiahnuté v Obchodnom registri.
Ak hľadáte profesionálne riešenie s prístupom ku všetkým 1.7 mil. subjektov
pôsobiacich v podnikateľskom prostredí SR, pozrite projekt https://bizdata.sk.

Parser for Business Directory of Slovak Republic allows accessing cca 480k
companies. However, it does not provide access to ie. enterpreneurs or unprofitable
organizations, since these are not contained within the Business Directory.
If you are looking for a professional solution with access to all 1.7 mil.
of subjects, check out the project at https://bizdata.sk.
======================================
EOD;
		if (PHP_SAPI === 'cli' || PHP_SAPI === 'phpdbg') {
			fwrite(STDOUT, PHP_EOL.$notes.PHP_EOL);
		} elseif (!headers_sent()) {
			echo PHP_EOL.$notes.PHP_EOL;
		}
	}
}
