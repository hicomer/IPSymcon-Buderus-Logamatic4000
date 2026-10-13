# CLAUDE.md – IP-Symcon-Modul Buderus Logamatic 4000

## Ziel

IP-Symcon-Modul zur Kopplung (Anzeige und Bedienung) einer Buderus-Ölheizung mit Regelgerät
**Logamatic 4211, ECO-CAN-Adresse 0**, über die RS232-Schnittstelle (Gateway, Prozedur 3964R, 9600 Baud).

## Anforderungen (vom Auftraggeber)

1. **Anbindung**: RS232 über einen COM-Port-nach-LAN-Adapter, hier ExSys EX-6034 (TCP, transparent).
   IP-Symcon läuft auf einem Raspberry Pi 4 mit Debian Bookworm.
2. **Dokumentation** liegt als PDF im Ordner `Dokumentation Buderus` (außerhalb dieses Repos, eine Ebene höher):
   * `Prozedur R3964.pdf` – Übertragungsprozedur
   * `Monitordaten_System_4000_V1_7747004149_01-2009.pdf` – Messwerte, Betriebszustände, Fehlerliste
   * `Einstellbare Parameter Logamatic 4000_7747004150_05-2009.pdf` – schreibbare Parameter, Schaltuhr
3. **Anzeige** aller Werte und **Anzeige aller Störmeldungen** (Klartext, Kommt/Geht-Historie).
4. **Manuelle Bedienung aller Funktionen**, also alle in der Parameter-Doku beschriebenen einstellbaren
   Parameter (Heizkreise, Warmwasser, Kessel, Solar, Strategiemodule, Schaltuhr/Wochenprogramme,
   Party/Pause/Ferien, Datum/Uhrzeit).
5. **Automatismen**, erstes Beispiel **Legionellen-Funktion**:
   * Das Regelgerät hat ein Wochenprogramm für die thermische Desinfektion, aber ohne Intelligenz.
   * Das Modul soll sie ausfallen lassen, wenn das Wasser in der Woche schon heiß genug war.
   * Im System ist ein Buderus-Solarmodul für Warmwasser-Kollektoren (FM443) vorhanden.
6. **Installation per URL** in Symcon (Git-Repository, vermutlich GitHub).

## Anlage des Auftraggebers

* Logamatic **4211**, CAN-Adresse **0**. HK0 (Kesselkreis, ungemischt) steckt im Protokoll unter
  **Heizkreis 5** (Monitor-Typ `0x8A`, Parameter-Typ `0x16`, Schaltkanal 5).
* Warmwasser: Schaltkanal 6, Zirkulationspumpe: Schaltkanal 10.
* Ölbrenner (bodenstehender Kessel, Typ `0x88`), Solarmodul FM443 (Typ `0x9E`).
* Voreinstellung im Modul: HK5, Warmwasser, Kessel, Solar an; HK1–4 und Strategiemodule aus.

## Adapter-Konfiguration (ExSys EX-6034)

* IP **192.168.20.70**, TCP-Port **102**, seriell **9600 Baud, 8N1**.
* Erreichbarkeit vom Entwicklungs-PC geprüft: TCP-Port 102 offen (Ping wird nicht beantwortet, unkritisch).
* In Symcon: Client Socket mit Host 192.168.20.70, Port 102; Modul-Instanz mit CAN-Adresse 0.

## Architektur

```
Logamatic4000/module.php   Symcon-Modul (Typ Device, Prefix BLM, Parent: Client Socket oder Serial Port)
libs/L3964.php             3964R-Zustandsautomat (STX/DLE, BCC, Wiederholungen, Initialisierungskonflikt)
libs/LogamaticData.php     Dekodierung Monitordaten, Fehlertabelle, Schaltuhr-Parser/-Encoder
tests/protocol_test.php    PHP-CLI-Tests ohne Symcon (php tests/protocol_test.php)
```

* **Job-Engine** (`Engine`, `Enqueue`, Puffer `ST` unter Semaphore): Befehlsfolgen laufen nacheinander.
  Poll: `0xDD`, `0xA2 addr` (Ende `0xAC`), optional `0xA1 addr` (Ende `0xAA`), `0xDC`.
  Schreiben: `0xDD`, `0xB0 …`, `0xDC`. Schreibjobs stehen vor Poll-Jobs.
* **Normal-Modus** liefert Änderungen (`0xA7`), **Direkt-Modus** Blöcke (`0xAB`); Fehler kommen als `0xAE`.
* **Parameter** werden über eine Tabelle (`ParamTable`) definiert: Typ, Offset, Byte, Bereich, Faktor.
  Nicht zu ändernde Bytes werden mit `0x65` gefüllt. Gleiche, bekannte Werte werden nicht geschrieben
  (max. 1.000.000 Schreibzyklen je Parameter).
* **Legionellen-Automatik** (`LegiTick`, minütlich): heiße Phase erfassen, N Stunden vor dem Termin
  entscheiden, Desinfektion aus; 2 Stunden nach dem Termin wieder ein (Sicherheitsnetz).

## Wichtige Protokolldetails

* 3964R: STX → DLE, Daten mit verdoppeltem DLE, `DLE ETX BCC` (BCC = XOR über Nutzdaten inkl. DLE ETX),
  Quittung DLE. Quittungsverzug 2 s, Zeichenverzug 220 ms (im Code bewusst großzügiger wegen TCP).
* Block-Offsets laufen in 7er-Schritten (6 Datenbytes pro Block); Index = `offset/7*6`. **Annahme für
  `0xAB`**, in der Parameter-Doku (4.5) für `0xA9` belegt. Einzelwerte `0xA7` verwenden den Offset direkt.
* Werte > 128 bei Temperaturen sind negativ (Zweierkomplement).
* In der PDF-Extraktion sind einzelne Byte-Positionen verrutscht (Zirkulationsläufe pro Stunde = Byte 5,
  Solar-Betriebsart = Byte 4, Kessel-Lastbegrenzung = Byte 4 u. a.). **Beim Test prüfen.**
* Schaltuhr: 90 Byte je Kanal (Programm, Pause, Party, Ferien, 2× 0xFF, 42 Schaltpunkte à 2 Byte),
  Zeitraster 10 Minuten, ungültiger Punkt = `194, 144`. Unit-Test vergleicht „Familie“ mit der Doku-Tabelle.

## Offener Stand / Nächste Schritte

* **Nicht an Hardware getestet.** Erster Test: Debug-Option an, Rohtelegramme prüfen (Offsets, Einheiten).
* Zurücklesen nicht-dokumentierter Parametertypen (`ReadParams`) ist experimentell.
* Brennerlaufzeiten werden als Minuten angenommen.
* Nicht umgesetzt: wandhängende Kessel (UBA-Monitorwerte), Wärmemengenzähler, Unterstation,
  Strategie-Monitorwerte (nur deren Parameter sind bedienbar), mehrere Regelgeräte je Instanz.
* Ideen für weitere Automatismen: Störungsmeldung per Push, Ferienmodus aus Anwesenheit,
  Warmwasser-Ladezeit nach PV-Überschuss.
* GitHub: `https://github.com/hicomer/IPSymcon-Buderus-Logamatic4000` (Repo noch anzulegen und zu pushen).

## Konventionen

* Sprache: Deutsch (Oberfläche, README, Kommentare); Bezeichner Englisch/Deutsch gemischt wie im Bestand.
* PHP 8, `declare(strict_types=1)`, keine Abhängigkeiten außerhalb von IP-Symcon.
* Änderungen an Protokollcode immer mit Eintrag in `tests/protocol_test.php`.
