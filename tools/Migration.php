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
 * vergleicht alte und neue Werte (Pruefung der Einheit, z.B. Brennerlaufzeit).
 *
 * Als Skript in Symcon anlegen und ausfuehren. Die Skript-Ausfuehrung ist bei vielen Archivwerten langsam
 * (Sekunden bis Minuten), das Zeitlimit des Skripts ggf. in den Skript-Einstellungen erhoehen.
 */

$INSTANCE_ID = 0;      // ID der Instanz "Logamatic 4000"
$DRY_RUN = true;       // true = nur anzeigen, false = ausfuehren
$REPLACE_IN_SCRIPTS = false; // true = alte ID in Skripttexten durch die neue ersetzen (nur mit Vorsicht)
$HIDE_OLD = false;     // true = alte Variable umbenennen ("... (alt)") und ausblenden

/**
 * alteVariablenID => ['ident' => Ident der neuen Variable, 'faktor' => Umrechnung alt -> neu (neu = alt * faktor)]
 *
 * Haeufige Idents: Aussentemperatur, AussentemperaturGedaempft, AnlagenVorlaufIst, WW_Ist, WW_Soll,
 * Kessel_VorlaufIst, Kessel_Abgastemperatur, Kessel_LaufzeitStufe1 (Stunden), Kessel_StartsStufe1,
 * Kessel_BrennerStufe, HK1_VorlaufIst, HK1_Pumpe, Solar_Kollektor, Solar_Speicher1Unten, Solar_Leistung,
 * Solar_Waermemenge1 (kWh), Solar_Betriebsstunden1 (Stunden).
 * Hat die alte Variable die Brennerlaufzeit in Sekunden, ist der Faktor fuer Stunden 1/3600, bei Minuten 1/60.
 */
$MAP = [
    // 12345 => ['ident' => 'Aussentemperatur', 'faktor' => 1.0],
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
mg_say($DRY_RUN ? '=== PROBELAUF (es wird nichts veraendert) ===' : '=== AUSFUEHRUNG ===');

foreach ($MAP as $old => $m) {
    $new = mg_find($INSTANCE_ID, $m['ident']);
    $f = (float) ($m['faktor'] ?? 1.0);
    mg_say("\n--- alt #$old " . (IPS_VariableExists($old) ? IPS_GetName($old) : '(fehlt)') . " -> neu '{$m['ident']}' #$new ---");
    if (!IPS_VariableExists($old) || $new === 0) {
        mg_say('ueberspringe: alte oder neue Variable nicht gefunden');
        continue;
    }

    // Pruefung der Einheit: alter und neuer Wert nebeneinander
    mg_say(sprintf('Wert alt = %s, alt*faktor = %s, neu = %s', json_encode(GetValue($old)), json_encode(GetValue($old) * $f), json_encode(GetValue($new))));

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

    // 4. Skripte mit der alten ID im Text
    foreach (IPS_GetScriptList() as $s) {
        $src = IPS_GetScriptContent($s);
        if (preg_match('/(?<!\d)' . $old . '(?!\d)/', $src)) {
            mg_say("Skript #$s (" . IPS_GetName($s) . ') enthaelt die ID ' . $old . ($REPLACE_IN_SCRIPTS ? ' -> wird ersetzt' : ' (bitte von Hand pruefen)'));
            if ($REPLACE_IN_SCRIPTS && !$DRY_RUN) {
                if ($f !== 1.0) {
                    mg_say('  NICHT ersetzt: Faktor ist ungleich 1, dort muss umgerechnet werden');
                } else {
                    IPS_SetScriptContent($s, preg_replace('/(?<!\d)' . $old . '(?!\d)/', (string) $new, $src));
                }
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
