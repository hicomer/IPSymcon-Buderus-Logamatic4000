# Buderus Logamatic 4000 für IP-Symcon

Symcon-Modul zur Anzeige und Bedienung einer Buderus Logamatic 4000 über das RS232-Gateway
(Prozedur 3964R, 9600 Baud), z. B. hinter einem COM-nach-LAN-Adapter (ExSys EX-6034).

**Status: 1.0, ungetestet an echter Hardware.** Die Protokollschicht (3964R) hat Unit-Tests, die
Zuordnung der Bytes basiert vollständig auf den Buderus-Dokumenten (Monitordaten System 4000 01/2009,
Einstellbare Parameter 05/2009, Prozedur 3964R). Siehe „Offene Punkte“.

## Installation

1. In IP-Symcon: *Kern-Instanzen → Module → Hinzufügen* und die URL dieses Repositories eintragen
   (`https://github.com/hicomer/IPSymcon-Buderus-Logamatic4000`). Symcon lädt Module aus Git-Repositories.
2. Instanz *Logamatic 4000* anlegen. Das Modul erzeugt automatisch einen **Client Socket** als übergeordnete
   Instanz. Dort die IP des ExSys-Adapters und den TCP-Port eintragen.
3. CAN-Adresse (Drehcodierschalter am Regelgerät) und die vorhandenen Module (Heizkreise, Warmwasser, Solar …)
   ankreuzen.

### ExSys-Adapter

Beispiel (getestet auf Erreichbarkeit): IP 192.168.20.70, Port 102, 9600 8N1.

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
  Kesselkreis, Abgasgrenze; Solar (Betriebsart, Verbraucher, Speichertemperaturen, Glykol); Uhr stellen.
* Schreibzugriffe mit identischem, bekanntem Wert werden unterdrückt (Schreibzyklen der Regelung sind
  auf 1.000.000 je Parameter begrenzt).

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
Die Automatik braucht die Werte *Desinfektion Ein/Aus, Tag, Stunde*. Sie müssen einmal gesetzt (im
WebFront) oder zurückgelesen werden; bis dahin zeigt *Legionellen-Status* „Warte …“.
Die Entscheidung läuft mit dem Warmwasserfühler des Regelgeräts; sitzt der unten im Speicher ungünstig,
kann eine andere Temperaturvariable gewählt werden.

## Offene Punkte (bitte beim ersten Test prüfen)

* **Offsets bei Blöcken**: Die Doku nennt für 6-Byte-Blöcke Offsets in 7er-Schritten (0, 7, 14 …). Das Modul
  rechnet Block-Offsets (`0xAB`, `0xA9`) mit `offset/7*6` um, Einzelwerte (`0xA7`) direkt. Stimmt das
  nicht, zeigt das Debug-Fenster (Option *Debug*) die Rohtelegramme.
* **Parameter zurücklesen** (`0xA1`/`0xA9`) ist nur für die Schaltuhr dokumentiert. Die Option nimmt an,
  dass die übrigen Typen dasselbe Layout wie beim Schreiben haben. Standardmäßig aus.
* Mehrere Zuordnungen von Byte-Positionen in der PDF-Tabelle sind im Text verrutscht (z. B. Zirkulations-
  läufe/h = Byte 5 bei Offset 0x0E, Solar-Betriebsart = Byte 4). Erst an unkritischen Werten testen.
* Brennerlaufzeiten werden als **Minuten** interpretiert (Einheit steht nicht in der Doku).
* Nicht enthalten: Monitorwerte der wandhängenden Kessel (UBA), der Strategiemodule, Wärmemengenzähler,
  Unterstation, Mehrfach-Regelgeräte in einer Instanz. Die Parameter der Strategiemodule sind bedienbar.

## Schaltuhr (Wochenprogramme)

Je Heizkreis, Warmwasser und Zirkulation gibt es ein *Schaltprogramm* (Familie, Früh, Spät, Vormittag,
Nachmittag, Mittag, Single, Senior, Leer) und ein Textfeld *Schaltuhr*, z. B.

```
Mo-Do 05:30-22:00; Fr 05:30-23:00; Sa 06:30-23:30; So 07:00-22:00
Mo-Fr 06:00-08:00,16:00-22:00; Sa,So 08:00-22:00
```

Zeiten im 10-Minuten-Raster, höchstens 21 Zeitspannen (42 Schaltpunkte), Ende spätestens 23:50.
Es wird immer der ganze Block geschrieben. Die Schaltuhr wird beim Verbinden und per Button
*Parameter lesen* aus dem Regelgerät gelesen (dieses Layout ist in der Doku beschrieben).

Voreinstellung für eine **Logamatic 4211** (CAN-Adresse 0): nur Heizkreis 5 (= HK0 / Kesselkreis),
Warmwasser, Kessel und Solar sind aktiv. Gibt es zusätzliche Heizkreismodule (FM442), diese ankreuzen.

## Tests

```
php tests/protocol_test.php
```
