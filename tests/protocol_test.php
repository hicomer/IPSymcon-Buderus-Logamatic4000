<?php

declare(strict_types=1);

// Aufruf: php tests/protocol_test.php   (reine PHP-CLI, ohne IP-Symcon)
require __DIR__ . '/../libs/L3964.php';
require __DIR__ . '/../libs/LogamaticData.php';

function check(bool $ok, string $what): void
{
    echo ($ok ? 'ok   ' : 'FAIL ') . $what . "\n";
    if (!$ok) {
        exit(1);
    }
}

// Block-Kodierung: DLE wird verdoppelt und fliesst doppelt in den BCC ein
$blk = L3964::encodeBlock("\xA2\x10\x01");
check(bin2hex($blk) === 'a2101001' . '1003' . bin2hex(chr(0xA2 ^ 0x10 ^ 0x10 ^ 0x01 ^ 0x10 ^ 0x03)), 'encodeBlock mit DLE-Verdopplung');

// Empfang: STX -> DLE, Block, BCC ok -> DLE
$link = new L3964();
$r = $link->feed("\x02", 1.0);
check($r['send'] === "\x10", 'STX wird mit DLE beantwortet');
$r = $link->feed(L3964::encodeBlock("\xAB\x00\x84\x00\x01\x02\x03\x04\x05\x06"), 1.1);
check($r['rx'] === ["\xAB\x00\x84\x00\x01\x02\x03\x04\x05\x06"] && $r['send'] === "\x10", 'Block empfangen und quittiert');

// Empfang mit DLE im Nutzbyte
$link = new L3964();
$link->feed("\x02", 1.0);
$r = $link->feed(L3964::encodeBlock("\xA7\x10\x80"), 1.1);
check($r['rx'] === ["\xA7\x10\x80"], 'DLE im Nutzbereich');

// Falscher BCC -> NAK
$link = new L3964();
$link->feed("\x02", 1.0);
$bad = L3964::encodeBlock("\xAC\x00");
$bad[strlen($bad) - 1] = chr(ord($bad[strlen($bad) - 1]) ^ 0xFF);
$r = $link->feed($bad, 1.1);
check($r['rx'] === [] && $r['send'] === "\x15", 'falscher BCC ergibt NAK');

// Senden: STX, DLE, Block, DLE
$link = new L3964();
check($link->send("\xDD", 1.0) === "\x02", 'Senden beginnt mit STX');
$r = $link->feed("\x10", 1.1);
check($r['send'] === L3964::encodeBlock("\xDD"), 'nach DLE folgt der Block');
$r = $link->feed("\x10", 1.2);
check($r['done'] === true && $link->isIdle(), 'Quittung beendet Sendung');

// Senden: keine Antwort -> nach 3 STX Abbruch
$link = new L3964();
$link->send("\xDD", 0.0);
$fails = 0;
for ($t = 4.0; $t < 20.0; $t += 4.0) {
    $r = $link->tick($t);
    $fails += $r['fail'] ? 1 : 0;
}
check($fails === 1 && $link->isIdle(), 'Abbruch nach drei Verbindungsversuchen');

// Initialisierungskonflikt
$link = new L3964();
$link->send("\xDD", 0.0);
$r = $link->feed("\x02", 0.1);
check($r['conflict'] === true && $r['send'] === "\x10", 'Initialisierungskonflikt: Gegenstelle hat Vorrang');

// Zustand ueberlebt Serialisierung
$link = new L3964();
$link->feed("\x02\xAB\x00", 1.0);
$copy = L3964::fromArray(json_decode(json_encode($link->toArray()), true));
$r = $copy->feed(substr(L3964::encodeBlock("\xAB\x00"), 2), 1.1);
check($r['rx'] === ["\xAB\x00"], 'Serialisierung des Zustands');

// Binaerdaten <-> JSON-String
$all = implode('', array_map('chr', range(0, 255)));
check(L3964::fromJsonString(L3964::toJsonString($all)) === $all, 'Latin-1/UTF-8-Transport aller 256 Bytes');
check(L3964::toJsonString("\xDD") === "\xC3\x9D", 'DD wird als UTF-8 kodiert');

// Dekodierung
$hk = LogamaticData::decodeHeizkreis([0b00000100, 0b00000000, 40, 38, 42, 40, 0, 0, 100, 50]);
check($hk['Betriebsart'] === 2 && $hk['RaumSoll'] == 21 &&$hk['VorlaufIst'] === 38, 'Heizkreis-Dekodierung');
// Messwerte aus dem Betrieb (Kesselkreis HK0, Adresse 0, Typ 0x8A): 110 = kein Fuehler
$hk0 = LogamaticData::decodeHeizkreis([0x04, 0x01, 0x05, 0x6E, 0x00, 0x6E, 0, 0, 0, 0, 0x80]);
check($hk0['Betriebsart'] === 2 && $hk0['Sommer'] === true && $hk0['VorlaufSoll'] === 5 && !isset($hk0['VorlaufIst']) && !isset($hk0['RaumIst']), 'HK0: 110 = kein Fuehler wird ausgeblendet');
$k = LogamaticData::decodeKonfig([0xFB, 0xFC]);
check($k['Aussentemperatur'] === -5 && $k['AussentemperaturGedaempft'] === -4, 'negative Aussentemperatur');
$ww = LogamaticData::decodeWarmwasser([0b00000011, 0b00100000, 60, 58, 0, 0b00000001]);
check($ww['DesinfektionAktiv'] === true && $ww['Betriebsart'] === 2 && $ww['Ladepumpe'] === true, 'Warmwasser-Dekodierung');
// Solar aus dem Betrieb: Kollektor 14,2 C, WMZ-Fuehler 255 = nicht vorhanden
$sol = array_fill(0, 36, 0);
$sol[3] = 0x00;
$sol[4] = 0x8E;
$sol[12] = 0xFF;
$sol[13] = 0xFF;
$sol[29] = 0x0E;
$so = LogamaticData::decodeSolar($sol);
check($so['Kollektor'] === 14.2 && !isset($so['Vorlauf']) && !isset($so['Ruecklauf']), 'Solar: Kollektor 14,2 und 255 = kein Fuehler');
check(LogamaticData::errorText(52) === 'Brennerstörung', 'Fehlertabelle');

// Schaltuhr: Standardprogramm "Familie" muss exakt der Tabelle der Doku (Seite 43-47) entsprechen
$days = LogamaticData::parseSchedule(LogamaticData::STD_PROGRAMS[1]);
check(is_array($days), 'Familie wird geparst');
$blk = LogamaticData::buildScheduleBlock(1, 0, 0, 0, $days);
check(strlen($blk) === 90, 'Schaltuhrblock hat 90 Byte');
$sb = [1, 0, 33, 32, 65, 64, 97, 96, 129, 128, 161, 160, 193, 192];
$tb = [33, 132, 33, 132, 33, 132, 33, 132, 33, 138, 39, 141, 42, 132];
$ok = true;
for ($i = 0; $i < 14; $i++) {
    $ok = $ok && ord($blk[6 + 2 * $i]) === $sb[$i] && ord($blk[7 + 2 * $i]) === $tb[$i];
}
check($ok, 'Schaltpunkte 0..13 wie in der Doku');
check(ord($blk[34]) === 194 && ord($blk[35]) === 144, 'ungueltige Schaltpunkte 194/144');
check(LogamaticData::scheduleToText(array_values(array_map('ord', str_split($blk)))) === 'Mo-Do 05:30-22:00; Fr 05:30-23:00; Sa 06:30-23:30; So 07:00-22:00', 'Block -> Text');
check(is_string(LogamaticData::parseSchedule('Mo 05:35-22:00')), '10-Minuten-Raster wird erzwungen');
check(is_string(LogamaticData::parseSchedule('Mo 05:30-22:00,21:00-23:00')), 'Ueberlappung wird abgelehnt');

echo "alle Tests bestanden\n";
