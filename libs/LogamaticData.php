<?php

declare(strict_types=1);

/**
 * Dekodierung der Monitordaten (Buderus "Monitordaten System 4000", 01/2009) und
 * Tabellen zu einstellbaren Parametern ("Einstellbare Parameter Logamatic 4000", 05/2009).
 *
 * Alle decode*-Funktionen erwarten die Rohbytes eines Datentyps als Array (Index = Offset
 * im Datentyp) und liefern ident => Wert. Fehlende Bytes ergeben kein Ergebnis fuer den Wert.
 */
final class LogamaticData
{
    // ---- Monitor-Datentypen (0xA7 / 0xAB) ----
    public const MT_HK = [1 => 0x80, 2 => 0x81, 3 => 0x82, 4 => 0x83, 5 => 0x8A]; // 5 = HK0 bei 4211/4221
    public const MT_WW = 0x84;
    public const MT_FEHLERPROTOKOLL = 0x87;
    public const MT_KESSEL = 0x88;
    public const MT_KONFIG = 0x89;
    public const MT_SOLAR = 0x9E;

    // ---- Parameter-Datentypen (0xB0 / 0xA9) ----
    public const PT_HK = [1 => 0x07, 2 => 0x08, 3 => 0x09, 4 => 0x0A, 5 => 0x16];
    public const PT_WW = 0x0C;
    public const PT_KESSEL = 0x10;
    public const PT_SOLAR = 0x24;
    public const PT_DATUM = 0x01;
    /** Schaltkanal (1..10) => Datentyp */
    public const PT_KANAL = [1 => 0x11, 2 => 0x12, 3 => 0x13, 4 => 0x14, 5 => 0x15, 6 => 0x17, 7 => 0x19, 8 => 0x1B, 9 => 0x1D, 10 => 0x1F];

    /** Wert fuer "Byte nicht aendern" */
    public const KEEP = 0x65;

    public static function bit(?int $v, int $n): ?bool
    {
        return $v === null ? null : ((($v >> ($n - 1)) & 1) === 1);
    }

    private static function s8(?int $v): ?int
    {
        if ($v === null) {
            return null;
        }
        return $v > 127 ? $v - 256 : $v;
    }

    private static function u(array $b, int ...$idx): ?int
    {
        $r = 0;
        foreach ($idx as $i) {
            if (!isset($b[$i])) {
                return null;
            }
            $r = ($r << 8) | ($b[$i] & 0xFF);
        }
        return $r;
    }

    private static function set(array &$o, string $k, $v): void
    {
        if ($v !== null) {
            $o[$k] = $v;
        }
    }

    public static function decodeHeizkreis(array $b): array
    {
        $o = [];
        $bw1 = $b[0] ?? null;
        $bw2 = $b[1] ?? null;
        self::set($o, 'AusschaltOpt', self::bit($bw1, 1));
        self::set($o, 'EinschaltOpt', self::bit($bw1, 2));
        self::set($o, 'WWVorrang', self::bit($bw1, 4));
        self::set($o, 'Estrich', self::bit($bw1, 5));
        self::set($o, 'Ferien', self::bit($bw1, 6));
        self::set($o, 'Frostschutz', self::bit($bw1, 7));
        if ($bw1 !== null && $bw2 !== null) {
            $auto = self::bit($bw1, 3);
            $tag = self::bit($bw2, 2);
            $o['Betriebsart'] = $auto ? 2 : ($tag ? 1 : 0);
        }
        self::set($o, 'Sommer', self::bit($bw2, 1));
        self::set($o, 'Tagbetrieb', self::bit($bw2, 2));
        self::set($o, 'KeineKommFB', self::bit($bw2, 3));
        self::set($o, 'FBDefekt', self::bit($bw2, 4));
        self::set($o, 'FehlerVorlauffuehler', self::bit($bw2, 5));
        self::set($o, 'MaxVorlauf', self::bit($bw2, 6));
        self::set($o, 'ExternStoerung', self::bit($bw2, 7));
        self::set($o, 'PartyPause', self::bit($bw2, 8));
        self::set($o, 'VorlaufSoll', $b[2] ?? null);
        self::set($o, 'VorlaufIst', $b[3] ?? null);
        self::set($o, 'RaumSoll', isset($b[4]) ? $b[4] / 2 : null);
        self::set($o, 'RaumIst', isset($b[5]) ? $b[5] / 2 : null);
        self::set($o, 'Pumpe', $b[8] ?? null);
        self::set($o, 'Stellglied', $b[9] ?? null);
        return $o;
    }

    public static function decodeWarmwasser(array $b): array
    {
        $o = [];
        $b1 = $b[0] ?? null;
        $b2 = $b[1] ?? null;
        self::set($o, 'Automatik', self::bit($b1, 1));
        self::set($o, 'DesinfektionAktiv', self::bit($b1, 2));
        self::set($o, 'Nachladung', self::bit($b1, 3));
        self::set($o, 'Ferien', self::bit($b1, 4));
        self::set($o, 'FehlerDesinfektion', self::bit($b1, 5));
        self::set($o, 'FehlerFuehler', self::bit($b1, 6));
        self::set($o, 'FehlerBleibtKalt', self::bit($b1, 7));
        self::set($o, 'FehlerAnode', self::bit($b1, 8));
        self::set($o, 'Laden', self::bit($b2, 1));
        self::set($o, 'Manuell', self::bit($b2, 2));
        self::set($o, 'Warm', self::bit($b2, 7));
        self::set($o, 'Vorrang', self::bit($b2, 8));
        self::set($o, 'Soll', $b[2] ?? null);
        self::set($o, 'Ist', $b[3] ?? null);
        if (isset($b[0], $b[1])) {
            $o['Betriebsart'] = self::bit($b[0], 1) ? 2 : (self::bit($b[1], 6) ? 1 : 0);
        }
        if (isset($b[8])) { // nur im Direkt-Modus
            $o['ZirkBetriebsart'] = self::bit($b[8], 2) ? 2 : (self::bit($b[8], 1) ? 1 : 0);
        }
        self::set($o, 'Ladepumpe', self::bit($b[5] ?? null, 1));
        self::set($o, 'Zirkulationspumpe', self::bit($b[5] ?? null, 2));
        self::set($o, 'FehlerExtern', self::bit($b[7] ?? null, 1));
        return $o;
    }

    public static function decodeKessel(array $b): array
    {
        $o = [];
        self::set($o, 'VorlaufSoll', $b[0] ?? null);
        self::set($o, 'VorlaufIst', $b[1] ?? null);
        self::set($o, 'BrennerEin', $b[2] ?? null);
        self::set($o, 'BrennerAus', $b[3] ?? null);
        $f = $b[6] ?? null;
        self::set($o, 'FehlerBrenner', self::bit($f, 1));
        self::set($o, 'FehlerKesselfuehler', self::bit($f, 2));
        self::set($o, 'FehlerKesselKalt', self::bit($f, 4));
        self::set($o, 'FehlerAbgasfuehler', self::bit($f, 5));
        self::set($o, 'FehlerAbgasGrenze', self::bit($f, 6));
        self::set($o, 'FehlerSicherheitskette', self::bit($f, 7));
        self::set($o, 'FehlerExtern', self::bit($f, 8));
        $be = $b[7] ?? null;
        self::set($o, 'Abgastest', self::bit($be, 1));
        self::set($o, 'Kesselschutz', self::bit($be, 3));
        self::set($o, 'LeistungFrei', self::bit($be, 5));
        self::set($o, 'BrennerStufe', $b[8] ?? null);
        self::set($o, 'Abgastemperatur', $b[9] ?? null);
        self::set($o, 'LaufzeitStufe1', self::u($b, 12, 13, 14)); // Minuten (Annahme)
        self::set($o, 'LaufzeitStufe2', self::u($b, 15, 16, 17));
        self::set($o, 'StartsStufe1', self::u($b, 18, 19, 20));
        self::set($o, 'StartsStufe2', self::u($b, 21, 22, 23));
        self::set($o, 'Kesselpumpe', $b[24] ?? null);
        self::set($o, 'RuecklaufSoll', $b[27] ?? null);
        self::set($o, 'Zusatzfuehler', $b[29] ?? null);
        self::set($o, 'MaxAbgas', $b[30] ?? null);
        if (isset($b[36])) { // Kessel Betrieb (nur Direkt-Modus): 1=Tag, 2=Automatik
            $o['Betriebsart'] = self::bit($b[36], 2) ? 2 : (self::bit($b[36], 1) ? 1 : 0);
        }
        return $o;
    }

    public static function decodeKonfig(array $b): array
    {
        $o = [];
        self::set($o, 'Aussentemperatur', self::s8($b[0] ?? null));
        self::set($o, 'AussentemperaturGedaempft', self::s8($b[1] ?? null));
        if (isset($b[2], $b[3])) {
            $o['Version'] = $b[2] . '.' . str_pad((string) $b[3], 2, '0', STR_PAD_LEFT);
        }
        self::set($o, 'AnlagenVorlaufSoll', $b[18] ?? null);
        self::set($o, 'AnlagenVorlaufIst', $b[19] ?? null);
        return $o;
    }

    public static function decodeSolar(array $b): array
    {
        $o = [];
        $b0 = $b[0] ?? null;
        $b1 = $b[1] ?? null;
        self::set($o, 'FehlerHysterese', self::bit($b0, 1));
        self::set($o, 'Speicher2Max', self::bit($b0, 2));
        self::set($o, 'Speicher1Max', self::bit($b0, 3));
        self::set($o, 'KollektorMax', self::bit($b0, 4));
        self::set($o, 'FehlerKollektorfuehler', self::bit($b1, 8));
        self::set($o, 'FehlerSpeicher1', self::bit($b1, 7));
        self::set($o, 'FehlerSpeicher2', self::bit($b1, 6));
        self::set($o, 'FehlerVolumenstrom', self::bit($b1, 3));
        if (isset($b[3], $b[4])) {
            $v = ($b[3] << 8) | $b[4];
            $o['Kollektor'] = ($v > 32767 ? $v - 65536 : $v) / 10;
        }
        self::set($o, 'Pumpe', $b[5] ?? null);
        self::set($o, 'Speicher1Unten', $b[6] ?? null);
        self::set($o, 'Status1', $b[7] ?? null);
        self::set($o, 'Speicher2Unten', $b[8] ?? null);
        self::set($o, 'Status2', $b[9] ?? null);
        self::set($o, 'Vorlauf', $b[12] ?? null);
        self::set($o, 'Ruecklauf', $b[13] ?? null);
        self::set($o, 'Volumenstrom', self::u($b, 14, 15));
        self::set($o, 'Leistung', self::u($b, 16, 17));
        $w1 = self::u($b, 18, 19, 20);
        $w2 = self::u($b, 21, 22, 23);
        self::set($o, 'Waermemenge1', $w1 === null ? null : $w1 / 10); // 100 Wh -> kWh
        self::set($o, 'Waermemenge2', $w2 === null ? null : $w2 / 10);
        $h1 = self::u($b, 24, 25, 26);
        $h2 = self::u($b, 30, 31, 32);
        self::set($o, 'Betriebsstunden1', $h1 === null ? null : round($h1 / 60, 1)); // min -> h
        self::set($o, 'Betriebsstunden2', $h2 === null ? null : round($h2 / 60, 1));
        return $o;
    }

    // ---- Schaltuhr (Wochenprogramm, 90 Byte je Schaltkanal) ----

    public const DAYS = ['Mo', 'Di', 'Mi', 'Do', 'Fr', 'Sa', 'So'];

    /** Standardprogramme 1..8 (Doku "Einstellbare Parameter" 4.4); 9 = leer. */
    public const STD_PROGRAMS = [
        1 => 'Mo-Do 05:30-22:00; Fr 05:30-23:00; Sa 06:30-23:30; So 07:00-22:00',
        2 => 'Mo-Do 04:30-22:00; Fr 04:30-23:00; Sa 06:30-23:30; So 07:00-22:00',
        3 => 'Mo-Fr 06:30-23:00; Sa 06:30-23:30; So 07:00-23:00',
        4 => 'Mo-Do 05:30-08:30,12:00-22:00; Fr 05:30-08:30,12:00-23:00; Sa 06:30-23:30; So 07:00-22:00',
        5 => 'Mo-Do 06:00-11:30,16:00-22:00; Fr 06:00-11:30,15:00-23:00; Sa 06:30-23:30; So 07:00-22:00',
        6 => 'Mo-Do 06:00-08:00,11:30-13:00,17:00-22:00; Fr 06:00-08:00,11:30-23:00; Sa 06:00-23:00; So 07:00-22:00',
        7 => 'Mo-Do 06:00-08:00,16:00-22:00; Fr 06:00-08:00,15:00-23:00; Sa 07:00-23:30; So 08:00-22:00',
        8 => 'täglich 05:30-22:00',
        9 => '',
    ];

    /**
     * Text wie "Mo-Fr 05:30-22:00; Sa,So 07:00-23:00, 12:00-13:00" in Intervalle je Wochentag wandeln.
     * Zeiten im 10-Minuten-Raster. Liefert [tag(0=Mo) => [[startMin, endMin], ...]] oder einen Fehlertext.
     *
     * @return array|string
     */
    public static function parseSchedule(string $text)
    {
        $days = array_fill(0, 7, []);
        foreach (preg_split('/[;\n]+/', $text) as $seg) {
            $seg = trim($seg);
            if ($seg === '') {
                continue;
            }
            if (!preg_match('/^([^\d]+?)\s*(\d.*)$/u', $seg, $m)) {
                return 'Unverständlich: "' . $seg . '"';
            }
            $sel = [];
            $dayPart = strtolower(str_replace('Ä', 'ä', trim($m[1]))); // ohne mbstring
            if ($dayPart === 'täglich' || $dayPart === 'taeglich') {
                $sel = range(0, 6);
            } else {
                foreach (explode(',', $dayPart) as $tok) {
                    $tok = trim($tok);
                    if (preg_match('/^([a-zäö]{2})\s*-\s*([a-zäö]{2})$/u', $tok, $r)) {
                        $a = array_search(ucfirst($r[1]), self::DAYS, true);
                        $b = array_search(ucfirst($r[2]), self::DAYS, true);
                        if ($a === false || $b === false) {
                            return 'Unbekannter Wochentag in "' . $tok . '"';
                        }
                        for ($d = $a; ; $d = ($d + 1) % 7) {
                            $sel[] = $d;
                            if ($d === $b) {
                                break;
                            }
                        }
                    } else {
                        $a = array_search(ucfirst($tok), self::DAYS, true);
                        if ($a === false) {
                            return 'Unbekannter Wochentag "' . $tok . '"';
                        }
                        $sel[] = $a;
                    }
                }
            }
            if (!preg_match_all('/(\d{1,2}):(\d{2})\s*-\s*(\d{1,2}):(\d{2})/', $m[2], $iv, PREG_SET_ORDER)) {
                return 'Keine Zeitspanne in "' . $seg . '"';
            }
            foreach ($iv as $i) {
                $s = (int) $i[1] * 60 + (int) $i[2];
                $e = (int) $i[3] * 60 + (int) $i[4];
                if ($s % 10 !== 0 || $e % 10 !== 0) {
                    return 'Zeiten nur im 10-Minuten-Raster: ' . $i[0];
                }
                if ($s >= $e || $e > 23 * 60 + 50) {
                    return 'Ungültige Zeitspanne (Ende nach Beginn, spätestens 23:50): ' . $i[0];
                }
                foreach ($sel as $d) {
                    $days[$d][] = [$s, $e];
                }
            }
        }
        $n = 0;
        foreach ($days as $d => &$list) {
            usort($list, fn ($x, $y) => $x[0] <=> $y[0]);
            for ($k = 1; $k < count($list); $k++) {
                if ($list[$k][0] <= $list[$k - 1][1]) {
                    return 'Überlappende Zeitspannen am ' . self::DAYS[$d];
                }
            }
            $n += count($list) * 2;
        }
        unset($list);
        if ($n > 42) {
            return 'Zu viele Schaltpunkte (' . $n . ', maximal 42)';
        }
        return $days;
    }

    /** 90-Byte-Schaltuhrblock bauen. $days wie von parseSchedule(). */
    public static function buildScheduleBlock(int $program, int $pause, int $party, int $holiday, array $days): string
    {
        $pt = [];
        foreach ($days as $d => $list) {
            foreach ($list as [$s, $e]) {
                $pt[] = [$d, $s, 1];
                $pt[] = [$d, $e, 0];
            }
        }
        $blk = chr($program) . chr($pause) . chr($party) . chr($holiday) . "\xFF\xFF";
        for ($i = 0; $i < 42; $i++) {
            if (isset($pt[$i])) {
                [$d, $min, $on] = $pt[$i];
                $sb = ($on ? 1 : 0) | (($d & 1) << 5) | ((($d >> 1) & 1) << 6) | ((($d >> 2) & 1) << 7);
                $blk .= chr($sb) . chr(intdiv($min, 10));
            } else {
                $blk .= chr(194) . chr(144); // ungueltiger Schaltpunkt
            }
        }
        return $blk;
    }

    /** Schaltuhrblock (Index => Byte, 90 Eintraege) in Text wandeln, null wenn unvollstaendig. */
    public static function scheduleToText(array $b): ?string
    {
        for ($i = 0; $i < 90; $i++) {
            if (!isset($b[$i])) {
                return null;
            }
        }
        $per = array_fill(0, 7, []);
        for ($i = 0; $i < 42; $i++) {
            $sb = (int) $b[6 + 2 * $i];
            $tb = (int) $b[7 + 2 * $i];
            if (($sb & 2) !== 0) {
                continue;
            }
            $d = (($sb >> 5) & 1) | ((($sb >> 6) & 1) << 1) | ((($sb >> 7) & 1) << 2);
            if ($d > 6) {
                continue;
            }
            $per[$d][] = [$tb * 10, ($sb & 1) === 1];
        }
        $txt = [];
        foreach ($per as $d => $pts) {
            usort($pts, fn ($x, $y) => $x[0] <=> $y[0]);
            $iv = [];
            $start = null;
            foreach ($pts as [$min, $on]) {
                if ($on) {
                    $start = $min;
                } elseif ($start !== null) {
                    $iv[] = sprintf('%02d:%02d-%02d:%02d', intdiv($start, 60), $start % 60, intdiv($min, 60), $min % 60);
                    $start = null;
                }
            }
            $txt[$d] = implode(',', $iv);
        }
        // aufeinanderfolgende Tage mit gleichem Programm zusammenfassen
        $out = [];
        $d = 0;
        while ($d < 7) {
            if ($txt[$d] === '') {
                $d++;
                continue;
            }
            $e = $d;
            while ($e + 1 < 7 && $txt[$e + 1] === $txt[$d]) {
                $e++;
            }
            $out[] = ($e > $d ? self::DAYS[$d] . '-' . self::DAYS[$e] : self::DAYS[$d]) . ' ' . $txt[$d];
            $d = $e + 1;
        }
        return implode('; ', $out);
    }

    /** Offene Fehler aus dem Fehlerprotokoll (0xAE) in lesbare Texte. */
    public static function errorText(int $code): string
    {
        return self::ERRORS[$code] ?? ('Unbekannter Fehler Nr. ' . $code);
    }

    /** Fehlerliste aus Kapitel 3.1 der Monitordaten-Doku. */
    public const ERRORS = [
        1 => 'Strategievorlauffühler defekt', 2 => 'Außenfühler defekt', 3 => 'Vorlauffühler HK1 defekt',
        4 => 'Vorlauffühler HK2 defekt', 5 => 'Vorlauffühler HK3 defekt', 6 => 'Vorlauffühler HK4 defekt',
        8 => 'Warmwasserfühler defekt', 9 => 'Warmwasser bleibt kalt', 10 => 'Störung therm. Desinfektion',
        11 => 'Fernbedienung HK1 defekt', 12 => 'Fernbedienung HK2 defekt', 13 => 'Fernbedienung HK3 defekt',
        14 => 'Fernbedienung HK4 defekt', 15 => 'Keine Kommunikation mit Fernbed. HK1',
        16 => 'Keine Kommunikation mit Fernbed. HK2', 17 => 'Keine Kommunikation mit Fernbed. HK3',
        18 => 'Keine Kommunikation mit Fernbed. HK4', 20 => 'Störung Brenner 1', 21 => 'Störung Brenner 2',
        22 => 'Störung Brenner 3', 23 => 'Störung Brenner 4', 24 => 'Keine Verbindung mit Kessel 1',
        25 => 'Keine Verbindung mit Kessel 2', 26 => 'Keine Verbindung mit Kessel 3', 30 => 'Interner Fehler Nr. 1',
        31 => 'Interner Fehler Nr. 2', 32 => 'Interner Fehler Nr. 3', 33 => 'Interner Fehler Nr. 4',
        34 => 'Vorlauffühler HK5 defekt', 35 => 'Vorlauffühler HK6 defekt', 36 => 'Vorlauffühler HK7 defekt',
        37 => 'Vorlauffühler HK8 defekt', 39 => 'Fernbedienung HK5 defekt', 40 => 'Fernbedienung HK6 defekt',
        41 => 'Fernbedienung HK7 defekt', 42 => 'Fernbedienung HK8 defekt',
        44 => 'Keine Kommunikation mit Fernbed. HK5', 45 => 'Keine Kommunikation mit Fernbed. HK6',
        46 => 'Keine Kommunikation mit Fernbed. HK7', 47 => 'Keine Kommunikation mit Fernbed. HK8',
        49 => 'Kesselvorlauffühler defekt', 50 => 'Kesselzusatzfühler defekt', 51 => 'Kessel bleibt kalt',
        52 => 'Brennerstörung', 53 => 'Störung Sicherheitskette', 54 => 'Externe Störung Kessel',
        55 => 'Abgasfühler defekt', 56 => 'Abgasgrenze überschritten', 57 => 'Externer Störeingang (Pumpe) HK1',
        58 => 'Externer Störeingang (Pumpe) HK2', 59 => 'Externer Störeingang (Pumpe) HK3',
        60 => 'Externer Störeingang (Pumpe) HK4', 61 => 'Externer Störeingang (Pumpe) HK5',
        62 => 'Externer Störeingang (Pumpe) HK6', 63 => 'Externer Störeingang (Pumpe) HK7',
        64 => 'Externer Störeingang (Pumpe) HK8', 66 => 'Interner Fehler Nr. 5', 67 => 'Interner Fehler Nr. 6',
        68 => 'Interner Fehler Nr. 7', 69 => 'Interner Fehler Nr. 8', 70 => 'Kein Master (Adr. 1) vorhanden',
        71 => 'Adresskonflikt auf CAN-Bus', 72 => 'Adresskonflikt auf Steckplatz 1',
        73 => 'Adresskonflikt auf Steckplatz 2', 74 => 'Adresskonflikt auf Steckplatz 3',
        75 => 'Adresskonflikt auf Steckplatz 4', 76 => 'Adresskonflikt auf Steckplatz A',
        77 => 'Falsches Modul auf Steckplatz 1', 78 => 'Falsches Modul auf Steckplatz 2',
        79 => 'Falsches Modul auf Steckplatz 3', 80 => 'Falsches Modul auf Steckplatz 4',
        81 => 'Falsches Modul auf Steckplatz A', 82 => 'Unbekanntes Modul auf Steckplatz 1',
        83 => 'Unbekanntes Modul auf Steckplatz 2', 84 => 'Unbekanntes Modul auf Steckplatz 3',
        85 => 'Unbekanntes Modul auf Steckplatz 4', 86 => 'Unbekanntes Modul auf Steckplatz A',
        87 => 'Rücklauffühler defekt', 88 => 'Ext. Störeingang (Inertanode) WW', 89 => 'Ext. Störeingang (Pumpe) WW',
        90 => 'Konfiguration Rücklauf bei Strategie', 91 => 'Konfiguration Vorlauf bei Strategie', 92 => 'RESET',
        93 => 'Handschalter Heizkreis 1', 94 => 'Handschalter Heizkreis 2', 95 => 'Handschalter Heizkreis 3',
        96 => 'Handschalter Heizkreis 4', 97 => 'Handschalter Heizkreis 5', 98 => 'Handschalter Heizkreis 6',
        99 => 'Handschalter Heizkreis 7', 100 => 'Handschalter Heizkreis 8', 101 => 'Handschalter Warmwasser',
        102 => 'Handschalter Brenner', 103 => 'Handschalter Kesselkreis', 104 => 'Strategiemodul fehlt',
        105 => 'Handschalter LAP Primärpumpe', 106 => 'Handschalter LAP Sekundärpumpe',
        107 => 'Wärmetauscherfühler LAP defekt', 108 => 'Speicher-unten-Fühler LAP defekt',
        109 => 'Warmwasser-Solarfühler defekt', 110 => 'Kollektorfühler defekt', 111 => 'Störung Brenner 5',
        112 => 'Störung Brenner 6', 113 => 'Störung Brenner 7', 114 => 'Störung Brenner 8',
        115 => 'Keine Verbindung mit Brennerautomat 1', 116 => 'Keine Verbindung mit Brennerautomat 2',
        117 => 'Keine Verbindung mit Brennerautomat 3', 118 => 'Keine Verbindung mit Brennerautomat 4',
        119 => 'Keine Verbindung mit Brennerautomat 5', 120 => 'Keine Verbindung mit Brennerautomat 6',
        121 => 'Keine Verbindung mit Brennerautomat 7', 122 => 'Keine Verbindung mit Brennerautomat 8',
        123 => 'Flaschenvorlauffühler defekt', 124 => '3-Wege-Umschaltventil defekt',
        125 => 'Füllstand: Grenze unterschritten', 126 => 'Unterstation Wärme-Unterversorgung',
        127 => 'Unterstation Vorlauffühler defekt', 128 => 'Kollektorfühler defekt', 129 => 'Bypass-Rücklauffühler defekt',
        130 => 'Bypass-Vorlauffühler defekt', 131 => 'Wärmemengenzähler Vorlauf defekt',
        132 => 'Wärmemengenzähler Rücklauf defekt', 133 => 'Speicher 1 Fühler unten defekt',
        134 => 'Speicher 2 Fühler unten defekt', 135 => 'Wärmemengenzähler Volumenstrommesser',
        136 => 'Fehlerhafte Einstellung Solarmodul', 137 => 'Heizkreis 1 EIB-Fehler', 138 => 'Heizkreis 2 EIB-Fehler',
        139 => 'Heizkreis 3 EIB-Fehler', 140 => 'Heizkreis 4 EIB-Fehler', 141 => 'Heizkreis 5 EIB-Fehler',
        142 => 'Heizkreis 6 EIB-Fehler', 143 => 'Heizkreis 7 EIB-Fehler', 144 => 'Heizkreis 8 EIB-Fehler',
        145 => 'Heizkreis 9 EIB-Fehler', 146 => 'Allgemeiner EIB-Fehler', 147 => 'Blockierender Fehler UBA',
        148 => 'Verriegelnder Fehler UBA', 149 => 'Handbetrieb Solar Speicher 1', 150 => 'Handbetrieb Solar Speicher 2',
        151 => 'Handbetrieb Heizkreis 0', 152 => 'Wartung erforderlich (Betriebsstunden)',
        153 => 'Wartung erforderlich (Datum)', 154 => 'Warmwasser ist kalt', 155 => 'Handbetrieb Zubringerpumpe (PZB)',
        156 => 'Handbetrieb EMS-Kessel 1', 157 => 'Handbetrieb EMS-Kessel 2', 158 => 'Handbetrieb EMS-Kessel 3',
        159 => 'Handbetrieb EMS-Kessel 4', 160 => 'Handbetrieb EMS-Kessel 5', 161 => 'Handbetrieb EMS-Kessel 6',
        162 => 'Handbetrieb EMS-Kessel 7', 163 => 'Handbetrieb EMS-Kessel 8', 164 => 'Störung EMS-Kessel 1',
        165 => 'Störung EMS-Kessel 2', 166 => 'Störung EMS-Kessel 3', 167 => 'Störung EMS-Kessel 4',
        168 => 'Störung EMS-Kessel 5', 169 => 'Störung EMS-Kessel 6', 170 => 'Störung EMS-Kessel 7',
        171 => 'Störung EMS-Kessel 8', 172 => 'Störung EMS-Warmwasser', 173 => 'Wartung erforderlich EMS-Kessel 1',
        174 => 'Wartung erforderlich EMS-Kessel 2', 175 => 'Wartung erforderlich EMS-Kessel 3',
        176 => 'Wartung erforderlich EMS-Kessel 4', 177 => 'Wartung erforderlich EMS-Kessel 5',
        178 => 'Wartung erforderlich EMS-Kessel 6', 179 => 'Wartung erforderlich EMS-Kessel 7',
        180 => 'Wartung erforderlich EMS-Kessel 8', 181 => 'Alternativer WE Pumpe im Handbetrieb',
        182 => 'Alternativer WE im Handbetrieb', 183 => 'Alternativer WE Rücklauffühler defekt',
        184 => 'Alternativer WE Vorlauffühler defekt', 185 => 'Alternativer WE Fühler Puffer mitte',
        186 => 'Alternativer WE Fühler Puffer unten', 187 => 'Alternativer WE Fühler Puffer oben',
        188 => 'Alternativer WE Anl.-Rücklauffühler', 189 => 'Alternativer WE Abgasfühler defekt',
        190 => 'Alternativer WE Kommunikation Brennerautomat', 191 => 'Alternativer WE Brennerautomat verriegelt',
        192 => 'Alternativer WE Notkühlung ausgelöst', 193 => 'FM458 Zuordnung Kessel 1', 194 => 'FM458 Zuordnung Kessel 2',
        195 => 'FM458 Zuordnung Kessel 3', 196 => 'FM458 Zuordnung Kessel 4', 197 => 'FM458 Zuordnung Kessel 5',
        198 => 'FM458 Zuordnung Kessel 6', 199 => 'FM458 Zuordnung Kessel 7', 200 => 'FM458 Zuordnung Kessel 8',
        201 => 'FM458 Keine Verbindung zu Kessel 1', 202 => 'FM458 Keine Verbindung zu Kessel 2',
        203 => 'FM458 Keine Verbindung zu Kessel 3', 204 => 'FM458 Keine Verbindung zu Kessel 4',
        205 => 'FM458 Keine Verbindung zu Kessel 5', 206 => 'FM458 Keine Verbindung zu Kessel 6',
        207 => 'FM458 Keine Verbindung zu Kessel 7', 208 => 'FM458 Keine Verbindung zu Kessel 8',
        209 => 'FM458 Fühler Vorlauf Strategie', 210 => 'FM458 Fühler Rücklauf Strategie',
        211 => 'FM458 Konfiguration Rücklauf Strategie', 212 => 'FM458 Konfiguration Vorlauf Strategie',
        213 => 'FM458 Leistungsangabe für Kessel fehlt',
    ];
}
