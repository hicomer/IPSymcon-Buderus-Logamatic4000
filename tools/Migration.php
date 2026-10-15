<?php

/**
 * Symcon-Skript: Alte Buderus-Variablen auf die Variablen des Moduls "Logamatic 4000" umstellen.
 *
 * Was es tut (je Zeile in $MAP):
 *  1. Verlauf: schaltet die Archivierung der neuen Variable ein (gleiche Aggregation wie die alte) und kopiert
 *     die archivierten Werte der alten Variable (nur aus der Zeit VOR dem ersten Wert der neuen Variable).
 *  2. Verknuepfungen (Links) zeigen danach auf die neue Variable.
 *  3. Ereignisse (Trigger auf Variable) loesen danach auf der neuen Variable aus.
 *  4. Skripte, die die alte ID im Text haben, werden nur aufgelistet (optional ersetzbar).
 *  5. Optional: alte Variable umbenennen und ausblenden.
 *
 * Nichts wird geloescht. Zuerst mit $DRY_RUN = true laufen lassen: das Skript zeigt nur, was es tun wuerde, und
 * vergleicht alte und neue Werte (Pruefung der Einheit, z.B. Brennerlaufzeit; der alte Wert ist eingefroren).
 *
 * Als Skript in Symcon anlegen und ausfuehren. Die Skript-Ausfuehrung ist bei vielen Archivwerten langsam
 * (Sekunden bis Minuten), das Zeitlimit des Skripts ggf. in den Skript-Einstellungen erhoehen.
 */

$INSTANCE_ID = 0;      // ID der Instanz "Logamatic 4000"
$SUGGEST_FROM = 0;       // ID der ALTEN Kategorie (z.B. 'Buderus'): schlaegt fertige $MAP-Zeilen vor und beendet das Skript
$LIST_VARIABLES = false; // true = nur alle Variablen des Moduls mit ID, Ident und Name auflisten
$DRY_RUN = true;       // true = nur anzeigen, false = ausfuehren
$REPLACE_IN_SCRIPTS = false; // true = alte ID in Skripttexten durch die neue ersetzen (nur mit Vorsicht)
$HIDE_OLD = false;     // true = alte Variable umbenennen ("... (alt)") und ausblenden

/**
 * alteVariablenID => [neue Variable angeben mit 'id' => ID ODER 'ident' => Ident, 'faktor' => Umrechnung alt -> neu (neu = alt * faktor)]
 * Der Ident ist der feste interne Name der neuen Variable (steht in der Liste mit $LIST_VARIABLES = true). Die ID geht genauso.
 *
 * Haeufige Idents: Aussentemperatur, AussentemperaturGedaempft, AnlagenVorlaufIst, WW_Ist, WW_Soll,
 * Kessel_VorlaufIst, Kessel_Abgastemperatur, Kessel_LaufzeitStufe1 (Stunden), Kessel_StartsStufe1,
 * Kessel_BrennerStufe, HK1_VorlaufIst, HK1_Pumpe, Solar_Kollektor, Solar_Speicher1Unten, Solar_Leistung,
 * Solar_Waermemenge1 (kWh), Solar_Betriebsstunden1 (Stunden).
 * Hat die alte Variable die Brennerlaufzeit in Sekunden, ist der Faktor fuer Stunden 1/3600, bei Minuten 1/60.
 */
$MAP = [
    // 12345 => ['ident' => 'Aussentemperatur', 'faktor' => 1.0],
    // 12346 => ['id' => 54321, 'faktor' => 1.0],   // neue Variable direkt per ID
    // 23456 => ['ident' => 'Kessel_LaufzeitStufe1', 'faktor' => 1 / 3600],
];

// ----------------------------------------------------------------------------------------------------------

function mg_find(int $instance, string $ident): int
{
    foreach (IPS_GetChildrenIDs($instance) as $c) {
        if (IPS_GetObject($c)['ObjectIdent'] === $ident) {
            return $c;
        }
        if (IPS_GetObject($c)['ObjectType'] === 0) {
            foreach (IPS_GetChildrenIDs($c) as $cc) {
                if (IPS_GetObject($cc)['ObjectIdent'] === $ident) {
                    return $cc;
                }
            }
        }
    }
    return 0;
}

function mg_say(string $s): void
{
    echo $s . "\n";
}

if ($INSTANCE_ID <= 0 || !IPS_InstanceExists($INSTANCE_ID)) {
    die("Bitte \$INSTANCE_ID auf die ID der Instanz 'Logamatic 4000' setzen.\n");
}
$archives = IPS_GetInstanceListByModuleID('{43192F0B-135B-4CE7-A0A7-1475603F3060}');
$arch = $archives[0] ?? 0;
if ($SUGGEST_FROM > 0) {
    // ---- Vorschlagsmodus: alte Variablen den neuen Modul-Variablen zuordnen (nach Namensaehnlichkeit) ----
    $norm = function (string $s): string {
        $s = strtolower(strtr($s, ['Ä' => 'ae', 'Ö' => 'oe', 'Ü' => 'ue', 'ä' => 'ae', 'ö' => 'oe', 'ü' => 'ue', 'ß' => 'ss']));
        $s = str_replace(['betriebsstunden', 'brennerstarts', 'starts'], ['laufzeit', 'starts', 'starts'], $s);
        return preg_replace('/[^a-z0-9]+/', '', $s);
    };
    $collect = function (int $root, string $path) use (&$collect, $INSTANCE_ID): array {
        $out = [];
        foreach (IPS_GetChildrenIDs($root) as $c) {
            if ($c === $INSTANCE_ID) {
                continue;
            }
            $p = $path . '\\' . IPS_GetName($c);
            if (IPS_VariableExists($c)) {
                $out[$c] = $p;
            } elseif (IPS_HasChildren($c)) {
                $out += $collect($c, $p);
            }
        }
        return $out;
    };
    $new = [];
    foreach (IPS_GetChildrenIDs($INSTANCE_ID) as $c) {
        $cat = IPS_GetObject($c)['ObjectType'] === 0 ? IPS_GetName($c) : '';
        foreach (($cat !== '' ? IPS_GetChildrenIDs($c) : [$c]) as $v) {
            if (IPS_VariableExists($v)) {
                $new[$v] = ['ident' => IPS_GetObject($v)['ObjectIdent'], 'key' => $norm($cat . ' ' . IPS_GetName($v)), 'idn' => $norm(IPS_GetObject($v)['ObjectIdent'])];
            }
        }
    }
    $done = array_keys($MAP);
    $doneNew = [];
    foreach ($MAP as $m) {
        $doneNew[] = isset($m['id']) ? (int) $m['id'] : mg_find($INSTANCE_ID, (string) ($m['ident'] ?? ''));
    }
    mg_say('=== VORSCHLAG fuer $MAP (bitte pruefen, Zeilen mit // sind unsicher) ===');
    foreach ($collect($SUGGEST_FROM, IPS_GetName($SUGGEST_FROM)) as $old => $path) {
        if (in_array($old, $done, true)) {
            continue;
        }
        $parts = explode('\\', $path);
        $o = $norm(implode(' ', array_slice($parts, -2)));
        $best = [0, 0];
        foreach ($new as $nid => $n) {
            if (in_array($nid, $doneNew, true)) {
                continue;
            }
            similar_text($o, $n['key'], $a);
            similar_text($o, $n['idn'], $b);
            $score = max($a, $b);
            $ov = GetValue($old);
            $nv = GetValue($nid);
            if (is_numeric($ov) && is_numeric($nv) && abs($ov) > 0 && abs($nv) > 0 && abs($nv / $ov - 1) < 0.3) {
                $score += 10; // aehnliche Werte
            }
            if ($score > $best[0]) {
                $best = [$score, $nid];
            }
        }
        if ($best[1] === 0) {
            mg_say("// $old  $path  -> kein Kandidat");
            continue;
        }
        $nid = $best[1];
        $ov = GetValue($old);
        $nv = GetValue($nid);
        $mark = $best[0] >= 70 ? '' : '// ';
        mg_say(sprintf("%s%d => ['id' => %d, 'faktor' => 1.0],  // %s -> %s (Aehnlichkeit %d%%) alt=%s neu=%s", $mark, $old, $nid, $path, $new[$nid]['ident'], $best[0], json_encode($ov), json_encode($nv)));
    }
    die();
}
if ($LIST_VARIABLES) {
    foreach (IPS_GetChildrenIDs($INSTANCE_ID) as $c) {
        $o = IPS_GetObject($c);
        $items = $o['ObjectType'] === 0 ? IPS_GetChildrenIDs($c) : [$c];
        foreach ($items as $v) {
            if (IPS_VariableExists($v)) {
                mg_say(sprintf('#%d  %-32s  %s', $v, IPS_GetObject($v)['ObjectIdent'], IPS_GetName($v)));
            }
        }
    }
    die();
}
mg_say($DRY_RUN ? '=== PROBELAUF (es wird nichts veraendert) ===' : '=== AUSFUEHRUNG ===');

foreach ($MAP as $old => $m) {
    $new = isset($m['id']) ? (int) $m['id'] : mg_find($INSTANCE_ID, (string) ($m['ident'] ?? ''));
    $f = (float) ($m['faktor'] ?? 1.0);
    mg_say("\n--- alt #$old " . (IPS_VariableExists($old) ? IPS_GetName($old) : '(fehlt)') . " -> neu '" . ($m['ident'] ?? $m['id'] ?? '?') . "' #$new ---");
    if (!IPS_VariableExists($old) || $new === 0 || !IPS_VariableExists($new)) {
        mg_say('ueberspringe: alte oder neue Variable nicht gefunden');
        continue;
    }

    // Pruefung der Einheit: alter und neuer Wert nebeneinander
    // (der alte Wert ist eingefroren, seit die alte Kopplung nicht mehr laeuft; Zaehler muessen neu >= alt*faktor sein)
    mg_say(sprintf('Wert alt = %s (zuletzt %s), alt*faktor = %s, neu = %s', json_encode(GetValue($old)), date('d.m.Y H:i:s', IPS_GetVariable($old)['VariableUpdated']), json_encode(GetValue($old) * $f), json_encode(GetValue($new))));

    // 1. Verlauf
    if ($arch > 0 && AC_GetLoggingStatus($arch, $old)) {
        $cut = PHP_INT_MAX;
        if (AC_GetLoggingStatus($arch, $new)) {
            $have = AC_GetLoggedValues($arch, $new, 0, time(), 10000);
            foreach ($have as $v) {
                $cut = min($cut, $v['TimeStamp']);
            }
        }
        mg_say('Archiv: kopiere Werte vor ' . ($cut === PHP_INT_MAX ? 'jetzt' : date('d.m.Y H:i:s', $cut)));
        if (!$DRY_RUN) {
            AC_SetLoggingStatus($arch, $new, true);
            AC_SetAggregationType($arch, $new, AC_GetAggregationType($arch, $old));
            IPS_ApplyChanges($arch);
            $end = min($cut - 1, time());
            $total = 0;
            while (true) {
                $vals = AC_GetLoggedValues($arch, $old, 0, $end, 10000); // neueste zuerst
                if (!$vals) {
                    break;
                }
                $batch = [];
                $oldest = $end;
                foreach ($vals as $v) {
                    $batch[] = ['TimeStamp' => $v['TimeStamp'], 'Value' => $v['Value'] * $f];
                    $oldest = min($oldest, $v['TimeStamp']);
                }
                usort($batch, fn ($a, $b) => $a['TimeStamp'] <=> $b['TimeStamp']);
                AC_AddLoggedValues($arch, $new, $batch);
                $total += count($batch);
                if (count($vals) < 10000 || $oldest >= $end) {
                    break;
                }
                $end = $oldest - 1;
            }
            AC_ReAggregateVariable($arch, $new);
            mg_say("Archiv: $total Werte kopiert");
        }
    } else {
        mg_say('Archiv: alte Variable wird nicht archiviert');
    }

    // 2. Verknuepfungen
    foreach (IPS_GetLinkList() as $l) {
        if (IPS_GetLink($l)['TargetID'] === $old) {
            mg_say("Link #$l (" . IPS_GetName($l) . ') -> neues Ziel');
            if (!$DRY_RUN) {
                IPS_SetLinkTargetID($l, $new);
            }
        }
    }

    // 3. Ereignisse
    foreach (IPS_GetEventList() as $e) {
        $ev = IPS_GetEvent($e);
        if ($ev['EventType'] === 0 && $ev['TriggerVariableID'] === $old) {
            mg_say("Ereignis #$e (" . IPS_GetName($e) . ') -> neue Trigger-Variable');
            if (!$DRY_RUN) {
                IPS_SetEventTrigger($e, $ev['TriggerType'], $new);
            }
        }
    }

    // 4. Skripte mit der alten ID im Text (das Migrationsskript selbst und Skripte ohne Datei werden uebersprungen)
    foreach (IPS_GetScriptList() as $s) {
        if ($s === ($_IPS['SELF'] ?? 0) || !@IPS_ScriptExists($s)) {
            continue;
        }
        $src = @IPS_GetScriptContent($s);
        if ($src === false || $src === '' || !preg_match('/(?<!\d)' . $old . '(?!\d)/', $src)) {
            continue;
        }
        mg_say("Skript #$s (" . IPS_GetName($s) . ') enthaelt die ID ' . $old . ($REPLACE_IN_SCRIPTS ? ' -> wird ersetzt' : ' (bitte von Hand pruefen):'));
        foreach (explode("\n", $src) as $n => $line) {
            if (preg_match('/(?<!\d)' . $old . '(?!\d)/', $line)) {
                mg_say(sprintf('    Zeile %d: %s', $n + 1, trim(mb_substr($line, 0, 140))));
            }
        }
        if ($REPLACE_IN_SCRIPTS && !$DRY_RUN) {
            if ($f !== 1.0) {
                mg_say('  NICHT ersetzt: Faktor ist ungleich 1, dort muss umgerechnet werden');
            } else {
                IPS_SetScriptContent($s, preg_replace('/(?<!\d)' . $old . '(?!\d)/', (string) $new, $src));
            }
        }
    }
    // 5. Alte Variable markieren
    if ($HIDE_OLD && !$DRY_RUN) {
        IPS_SetName($old, IPS_GetName($old) . ' (alt)');
        IPS_SetHidden($old, true);
    }
}
mg_say("\nFertig.");
