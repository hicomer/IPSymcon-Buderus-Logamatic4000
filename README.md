# Buderus Logamatic 4000 für IP-Symcon

Symcon-Modul zur Anzeige und Bedienung einer Buderus Logamatic 4000 über das RS232-Gateway
(Prozedur 3964R, 9600 Baud), z. B. hinter einem COM-nach-LAN-Adapter (ExSys EX-6034).

**Status: 1.0, läuft im Echtbetrieb an einer Logamatic 4211 (CAN-Adresse 0).** Lesen aller Monitorwerte,
Zurücklesen der Parameter und das Schreiben der Warmwasser-Solltemperatur sind am Gerät bestätigt.
Der Rest des Schreibens ist an der Anlage noch nicht jeweils einzeln geprüft, siehe „Offene Punkte“.
Grundlage sind die Buderus-Dokumente *Monitordaten System 4000* (01/2009), *Einstellbare Parameter* (05/2009)
und *Prozedur 3964R*.

## Installation

1. In IP-Symcon: *Kern-Instanzen → Module → Hinzufügen* und die URL dieses Repositories eintragen
   (`https://github.com/hicomer/IPSymcon-Buderus-Logamatic4000`). Symcon lädt Module aus Git-Repositories.
2. Instanz *Logamatic 4000* anlegen. Das Modul erzeugt automatisch einen **Client Socket** als übergeordnete
   Instanz. Dort die IP des ExSys-Adapters und den TCP-Port eintragen.
3. CAN-Adresse (Drehcodierschalter am Regelgerät) und die vorhandenen Module (Heizkreise, Warmwasser, Solar …)
   ankreuzen. Die Variablen erscheinen in Kategorien unter der Instanz (Heizkreis n, Warmwasser, Kessel, Solar,
   Störungen, Legionellen-Automatik, Diagnose).

### ExSys-Adapter

Beispiel (läuft so): IP 192.168.20.70, Port 102, 9600 8N1.

* Betriebsart **TCP-Server (transparent / „Socket“)**, Port frei wählbar.
* Seriell: **9600 Baud, 8 Datenbits**, Parität und Stoppbits laut Gateway-Einstellung des Regelgeräts
  (üblich 8N1). Kein Flow Control.
* Symcon darf nur **eine** TCP-Verbindung zum Adapter halten. Der Adapter darf keine eigenen Zeichen einfügen.
* Am CAN-Bus darf nur **ein** Kommunikationsmodul hängen (BS471/KM471, ECO-CAN-Tool oder RS232-Gateway).

## Was das Modul kann

* **Anzeige**: Außentemperatur, Heizkreise (Soll/Ist, Pumpe, Mischer, Betriebszustände), Warmwasser,
  Kessel/Brenner (Stufe, Laufzeiten, Starts, Abgas), Solar (Kollektor, Speicher, Leistung, Erträge),
  alle Störmeldungen im Klartext (213 Fehlercodes) mit Kommt/Geht-Historie.
* **Bedienung** (Variablen mit Aktion): Betriebsart, Tag-/Nachttemperatur, Sommer/Winter-Schwelle,
  Party, Pause, Ferientage und Ferien-Absenkart je Heizkreis; Warmwasser-Solltemperatur, Betriebsart,
  Zirkulation, tägliche Aufheizung, thermische Desinfektion (Ein/Aus, Temperatur, Tag, Stunde);
  Kesselkreis, Abgasgrenze, Brennerleistungen; Solar (Betriebsart, Verbraucher, Speichertemperaturen,
  Glykol); Strategiemodule; Schaltuhr/Wochenprogramme; Uhr stellen.
* Schreibzugriffe mit identischem, bekanntem Wert werden unterdrückt (Schreibzyklen der Regelung sind
  auf 1.000.000 je Parameter begrenzt).
* Die aktuellen Parameter werden beim Verbinden und per Button *Parameter lesen* aus dem Regelgerät gelesen.

## Legionellen-Automatik

Die Wochenschaltung der thermischen Desinfektion läuft im Regelgerät blind weiter, auch wenn das Wasser
(z. B. durch Solar) schon heiß genug war. Das Modul

1. beobachtet die Warmwassertemperatur (oder eine frei wählbare Variable) und merkt sich, wann sie
   **mindestens X °C für mindestens Y Minuten** erreicht hat,
2. entscheidet **N Stunden vor dem Desinfektionstermin**: War das innerhalb der letzten Z Tage der Fall,
   wird die thermische Desinfektion im Regelgerät ausgeschaltet,
3. schaltet sie **2 Stunden nach dem Termin wieder ein** (Sicherheitsnetz: fällt Symcon aus, bleibt die
   normale Desinfektion des Regelgeräts aktiv).

Voreinstellung: 60 °C, 20 Minuten, 6 Tage, Entscheidung 3 h vorher. Mit 6 Tagen liegen zwischen zwei
heißen Phasen höchstens knapp zwei Wochen; für strengere Anforderungen die Tage verkleinern.
Die Automatik braucht *Desinfektion Ein/Aus, Tag, Stunde*; das Modul liest sie beim Verbinden aus dem Regelgerät.
Die Desinfektion muss im Regelgerät **eingeschaltet** sein, sonst meldet die Automatik „manuell ausgeschaltet“
und tut nichts. Die Entscheidung läuft mit dem Warmwasserfühler des Regelgeräts; sitzt der unten im Speicher
ungünstig, kann eine andere Temperaturvariable gewählt werden.

## Schaltuhr (Wochenprogramme)

Je Heizkreis, Warmwasser und Zirkulation gibt es ein *Schaltprogramm* (Familie, Früh, Spät, Vormittag,
Nachmittag, Mittag, Single, Senior, Leer) und ein Textfeld *Schaltuhr*, z. B.

```
Mo-Do 05:30-22:00; Fr 05:30-23:00; Sa 06:30-23:30; So 07:00-22:00
Mo-Fr 06:00-08:00,16:00-22:00; Sa,So 08:00-22:00
```

Zeiten im 10-Minuten-Raster, höchstens 21 Zeitspannen (42 Schaltpunkte), Ende spätestens 23:50.
Es wird immer der ganze Block geschrieben.

Voreinstellung für eine **Logamatic 4211** (CAN-Adresse 0): nur Heizkreis 5 (= HK0 / Kesselkreis),
Warmwasser, Kessel und Solar sind aktiv. Gibt es zusätzliche Heizkreismodule (FM442), diese ankreuzen.

## Migration von vorhandenen Variablen (Archiv, Verknüpfungen, Ereignisse)

Wer schon Variablen mit Archiv, Links und Ereignissen hat, kann sie auf die neuen Variablen umstellen:

1. **Parallelbetrieb**: Modul einrichten und einige Tage neben den alten Variablen laufen lassen. Alte und neue
   Werte vergleichen, besonders **Einheiten** (z. B. Brennerlaufzeit: Stunden, Minuten oder Sekunden?).
2. [`tools/Migration.php`](tools/Migration.php) als Skript in Symcon anlegen, `$INSTANCE_ID` und die Tabelle
   `$MAP` (alte Variablen-ID → Ident der neuen Variable, Umrechnungsfaktor) füllen und mit `$DRY_RUN = true`
   starten. Es zeigt Wert-Vergleich, Archiv, Links, Ereignisse und Skripte, die die alte ID enthalten.
3. Mit `$DRY_RUN = false` ausführen. Das Skript schaltet die Archivierung der neuen Variable ein und kopiert den
   Verlauf der alten (nur aus der Zeit vor dem ersten Wert der neuen), zeigt alle Links und Ereignisse auf die
   neue Variable und listet Skripte mit der alten ID. Es löscht nichts.
4. **Skripte** mit festen Variablen-IDs (z. B. Öltank-Berechnung) von Hand auf die neue ID umstellen, bei geänderter
   Einheit den Faktor berücksichtigen. Alternativ die alte Variable als Brücke weiterführen: ein Ereignis auf der neuen
   Variable schreibt den Wert (umgerechnet) in die alte, dann bleiben alle Skripte unverändert.
5. Alte Variablen erst nach einer Beobachtungszeit löschen.

## Offene Punkte

Bestätigt am Gerät (Logamatic 4211): 3964R-Verbindung, Monitordaten (Heizkreis, Warmwasser, Kessel, Solar,
Konfiguration), Parameter-Dump und Rücklesen (Heizkreis, Warmwasser, Kessel, Solar, Schaltuhr), Schreiben der
Warmwasser-Solltemperatur. Offsets: `0xA7` Byte-Index, `0xAB` in 6er-Schritten, nur `0xA9` (Parameter) in 7er-Schritten.

Noch nicht jeweils einzeln am Gerät geprüft:

* **Schreiben** aller übrigen Parameter (Heizkreis, Kessel, Solar, Zirkulation, Schaltuhr, Party/Pause/Ferien).
  Erst an unkritischen Werten testen und danach zurückstellen.
* **Zirkulationsläufe pro Stunde** (Byte 5 bei Offset 0x0E, im Dump Wert 4) und **tägliche Aufheizung**
  (Offset 0x2A, im Dump nicht enthalten).
* **Ferien-Absenkart/Außenhalt** (Offset 0x3F) und **Brennerleistungen/Korrekturpunkte** (0x4D/0x54): nicht im
  Dump, daher kein Zurücklesen; die Schreibpositionen stammen nur aus dem PDF.
* **Einheiten**: Brennerlaufzeiten als Minuten angenommen; die Solar-Betriebsstunden ergeben unplausible Werte,
  die Einheit ist unklar. Mit dem Migrations-Vergleich alt/neu prüfen und ggf. den Faktor im Modul anpassen.
* **Mischer** ist als vorzeichenbehaftete Puls/Pause-Ansteuerung gedeutet (−100 % = zu).
* **Legionellen-Automatik** im Dauerbetrieb (Aussetzen und Wiedereinschalten über mehrere Wochen) noch nicht
  beobachtet. Ein Gateway-Telegramm `0xA5` ist unbekannt und wird ignoriert.
* **Wert „kein Fühler“** (110 bzw. 255) ist für HK-Vorlauf/Raum und Solar-Fühler abgefangen; andere Fühler
  könnten ähnliche Platzhalter liefern.

Nicht enthalten: Monitorwerte der wandhängenden Kessel (UBA), der Strategiemodule, Wärmemengenzähler,
Unterstation, mehrere Regelgeräte in einer Instanz. Die Parameter der Strategiemodule sind bedienbar.

Diagnose: Option *Debug* zeigt die Telegramme im Debug-Fenster und in der Variable *Telegrammprotokoll*;
`BLM_GetRaw(<ID>)` und `BLM_GetParams(<ID>)` geben die Rohdaten aus.

## Tests

```
php tests/protocol_test.php
```
