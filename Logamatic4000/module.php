<?php

declare(strict_types=1);

require_once __DIR__ . '/../libs/L3964.php';
require_once __DIR__ . '/../libs/LogamaticData.php';

/**
 * Buderus Logamatic 4000 ueber RS232-Gateway (Prozedur 3964R), z.B. hinter einem COM-nach-LAN-Adapter.
 *
 * Aufbau:
 *  - L3964          Zustandsautomat der 3964R-Prozedur (STX/DLE-Handshake, BCC)
 *  - Job-Engine     arbeitet Befehlsfolgen (Monitor lesen, Parameter schreiben) nacheinander ab
 *  - LogamaticData  Dekodierung der Monitordaten, Fehlertabelle
 *  - Legionellen-Automatik (LegiTick)
 */
class Logamatic4000 extends IPSModule
{
    private const IO_CLIENT_SOCKET = '{3CFF0FD9-E306-41DB-9B5A-9D06D38576C3}';
    private const DATA_TO_PARENT = '{79827379-F36E-4ADA-8A95-5F8D1DC92FA9}';

    private const T_BOOL = 0;
    private const T_INT = 1;
    private const T_FLOAT = 2;
    private const T_STRING = 3;

    /** @var array job-engine state, only valid inside Locked() */
    private array $st = [];
    private string $out = '';
    private ?array $defCache = null;
    private ?array $idMap = null;

    // ------------------------------------------------------------------ Lebenszyklus

    public function Create()
    {
        parent::Create();
        $this->RequireParent(self::IO_CLIENT_SOCKET);

        $this->RegisterPropertyInteger('Address', 0);
        $this->RegisterPropertyInteger('PollMinutes', 5);
        $this->RegisterPropertyBoolean('ReadParams', false);
        // Voreinstellung fuer eine Logamatic 4211: HK0 (= HK5 im Protokoll), Warmwasser, Kessel, Solar
        $this->RegisterPropertyBoolean('HK1', false);
        $this->RegisterPropertyBoolean('HK2', false);
        $this->RegisterPropertyBoolean('HK3', false);
        $this->RegisterPropertyBoolean('HK4', false);
        $this->RegisterPropertyBoolean('HK5', true);
        $this->RegisterPropertyBoolean('WW', true);
        $this->RegisterPropertyBoolean('Kessel', true);
        $this->RegisterPropertyBoolean('Solar', true);
        $this->RegisterPropertyBoolean('Strat447', false);
        $this->RegisterPropertyBoolean('StratUBA', false);
        $this->RegisterPropertyBoolean('StratFM458', false);

        $this->RegisterPropertyInteger('LegiMinTemp', 60);
        $this->RegisterPropertyInteger('LegiHoldMinutes', 20);
        $this->RegisterPropertyInteger('LegiValidDays', 6);
        $this->RegisterPropertyInteger('LegiHoursBefore', 3);
        $this->RegisterPropertyInteger('LegiSourceVar', 0);
        $this->RegisterPropertyBoolean('ClockSyncDaily', false);
        $this->RegisterPropertyBoolean('Debug', false);

        $this->RegisterAttributeString('Raw', '{}');     // Monitordaten: typ => [offset => byte]
        $this->RegisterAttributeString('Par', '{}');     // gelesene Parameter: typ => [index => byte]
        $this->RegisterAttributeString('Known', '[]');   // Parameter-Variablen mit bekanntem Istwert
        $this->RegisterAttributeString('Errors', '[]');  // aktuell offene Fehlercodes
        $this->RegisterAttributeString('History', '[]');
        $this->RegisterAttributeString('Trace', '[]');
        $this->RegisterAttributeInteger('LastRx', 0);
        $this->RegisterAttributeInteger('LastPoll', 0);
        $this->RegisterAttributeInteger('LastMinute', 0);
        $this->RegisterAttributeInteger('LastClockSync', 0);
        $this->RegisterAttributeInteger('LastParamRead', 0);
        $this->RegisterAttributeInteger('ParentID', 0);
        $this->RegisterAttributeInteger('LegiHotSince', 0);
        $this->RegisterAttributeInteger('LegiLast', 0);
        $this->RegisterAttributeBoolean('LegiSuppressed', false);
        $this->RegisterAttributeInteger('LegiSuppressedFor', 0);

        $this->RegisterTimer('Tick', 0, 'BLM_Tick($_IPS[\'TARGET\']);');
        $this->RegisterMessage(0, IPS_KERNELMESSAGE);
    }

    public function ApplyChanges()
    {
        parent::ApplyChanges();
        $this->defCache = null;

        $this->CreateProfiles();
        $this->idMap = null;
        $legiExisted = isset($this->IdMap()['Legi_Aktiv']);
        $usedCats = [];
        foreach ($this->Defs() as $d) {
            [$group, $ident, $name, $type, $profile, $action, $pos] = $d;
            $id = $this->IdMap()[$ident] ?? 0;
            if (!$this->GroupEnabled($group)) {
                if ($id > 0) {
                    IPS_DeleteVariable($id);
                    $this->idMap = null;
                }
                continue;
            }
            if ($id === 0) {
                // MaintainVariable findet nur direkte Kinder der Instanz, daher nur zum Neuanlegen verwenden
                $this->MaintainVariable($ident, $name, $type, $profile, $pos, true);
                $id = (int) @IPS_GetObjectIDByIdent($ident, $this->InstanceID);
                $this->idMap = null;
                if ($id === 0) {
                    continue;
                }
            }
            if (IPS_GetName($id) !== $name) {
                IPS_SetName($id, $name);
            }
            IPS_SetPosition($id, $pos);
            $cat = $this->CategoryOf($group, $ident);
            $parent = $this->InstanceID;
            if ($cat !== null) {
                $usedCats['cat_' . $cat[0]] = true;
                $parent = $this->EnsureCategory($cat);
            }
            if (IPS_GetObject($id)['ParentID'] !== $parent) {
                IPS_SetParent($id, $parent);
                $this->idMap = null;
            }
            if ($action) {
                IPS_SetVariableCustomAction($id, $this->InstanceID); // ruft RequestAction der Instanz auf
            }
        }
        foreach ($this->IdMap() as $ident => $cid) { // nicht mehr benoetigte Kategorien entfernen
            if (strncmp($ident, 'cat_', 4) === 0 && !isset($usedCats[$ident]) && count(IPS_GetChildrenIDs($cid)) === 0) {
                IPS_DeleteCategory($cid);
                $this->idMap = null;
            }
        }
        if ($this->GroupEnabled('LEGI') && !$legiExisted) {
            $this->SetV('Legi_Aktiv', true); // Erstanlage: Automatik standardmaessig an
        }

        $old = $this->ReadAttributeInteger('ParentID');
        if ($old > 0) {
            $this->UnregisterMessage($old, IM_CHANGESTATUS);
        }
        $parent = (int) (IPS_GetInstance($this->InstanceID)['ConnectionID'] ?? 0);
        $this->WriteAttributeInteger('ParentID', $parent);

        if (IPS_GetKernelRunlevel() !== KR_READY) {
            return;
        }
        if ($parent > 0) {
            $this->RegisterMessage($parent, IM_CHANGESTATUS);
        }
        if ($this->ReadPropertyInteger('Address') < 0 || $this->ReadPropertyInteger('Address') > 15) {
            $this->SetStatus(201);
            $this->SetTimerInterval('Tick', 0);
            return;
        }
        $this->SetStatus(102);
        $this->SetTimerInterval('Tick', 1000);

        if ($this->HasActiveParent()) {
            $this->OnConnect();
        }
    }

    public function MessageSink($TimeStamp, $SenderID, $Message, $Data)
    {
        if ($Message === IPS_KERNELMESSAGE && $Data[0] === KR_READY) {
            $this->ApplyChanges();
        } elseif ($Message === IM_CHANGESTATUS && $SenderID === $this->ReadAttributeInteger('ParentID')) {
            if ($Data[0] === IS_ACTIVE) {
                $this->OnConnect();
            }
        }
    }

    private function OnConnect(): void
    {
        $this->Locked(function () {
            $this->st = $this->NewState();
            $this->Enqueue($this->PollJob(true), false);
            $this->Engine(microtime(true));
        });
    }

    // ------------------------------------------------------------------ Oeffentliche Funktionen (BLM_*)

    /** Monitordaten (und ggf. Parameter) jetzt abfragen. */
    public function Poll(): void
    {
        $this->Locked(function () {
            $this->Enqueue($this->PollJob(false), false);
            $this->Engine(microtime(true));
        });
    }

    /** Einstellbare Parameter vom Regelgeraet lesen (experimentell, siehe README). */
    public function ReadParameters(): void
    {
        $this->Locked(function () {
            $this->Enqueue($this->PollJob(true), false);
            $this->Engine(microtime(true));
        });
    }

    /** Uhr des Regelgeraets auf die Symcon-Zeit stellen. */
    public function SyncClock(): void
    {
        $steps = $this->WriteSteps([$this->ClockFrame()]);
        $this->QueueJob(['kind' => 'clock', 'steps' => $steps, 'undo' => []], true);
        $this->WriteAttributeInteger('LastClockSync', time());
    }

    /** Legionellen-Pruefung sofort ausfuehren. */
    public function LegionellaCheck(): void
    {
        $this->LegiTick();
    }

    /** Aktuelle Rohdaten (Debug). */
    public function GetRaw(): string
    {
        return $this->ReadAttributeString('Raw');
    }

    public function Tick(): void
    {
        $now = microtime(true);
        $this->Locked(function () use ($now) {
            $link = L3964::fromArray($this->st['link']);
            $res = $link->tick($now);
            $this->st['link'] = $link->toArray();
            $this->OnLinkResult($res, $now);

            $poll = $this->ReadPropertyInteger('PollMinutes');
            if ($poll > 0 && time() - $this->ReadAttributeInteger('LastPoll') >= $poll * 60
                && $this->HasActiveParent() && !$this->JobPending('poll')) {
                $this->Enqueue($this->PollJob(false), false);
            }
            $this->Engine($now);
        });

        $this->SyncTrace();
        $minute = intdiv(time(), 60);
        if ($minute !== $this->ReadAttributeInteger('LastMinute')) {
            $this->WriteAttributeInteger('LastMinute', $minute);
            $this->LegiTick();
            $this->DailyClock();
        }
    }

    // ------------------------------------------------------------------ Bedienung

    public function RequestAction($Ident, $Value)
    {
        if ($Ident === 'Legi_Aktiv') {
            $this->SetV($Ident, (bool) $Value);
            if (!$Value && $this->ReadAttributeBoolean('LegiSuppressed')) {
                $this->WriteParam('WW_Desinfektion', 1, 'Legionellen-Automatik aus: Desinfektion wieder freigeben');
            }
            $this->LegiTick();
            return;
        }
        foreach ($this->Channels() as $prefix => $channel) {
            if ($Ident === $prefix . 'Schaltprogramm') {
                $this->WriteSchedule($prefix, $channel, (int) $Value, null);
                return;
            }
            if ($Ident === $prefix . 'Schaltuhr') {
                $this->WriteSchedule($prefix, $channel, 0, (string) $Value);
                return;
            }
        }
        $table = $this->ParamTable();
        if (!isset($table[$Ident])) {
            $this->LogMessage('Unbekannte Aktion ' . $Ident, KL_WARNING);
            return;
        }
        $this->WriteParam($Ident, $Value, 'Benutzer');
    }

    /** Schaltkanaele der aktiven Funktionen: Variablen-Praefix => Kanal 1..10 (4211: HK0 = Kanal 5, WW = 6, Zirkulation = 10). */
    private function Channels(): array
    {
        $c = [];
        foreach (array_keys(LogamaticData::PT_HK) as $n) {
            if ($this->ReadPropertyBoolean('HK' . $n)) {
                $c['HK' . $n . '_'] = $n;
            }
        }
        if ($this->ReadPropertyBoolean('WW')) {
            $c['WW_'] = 6;
            $c['WW_Zirk'] = 10;
        }
        return $c;
    }

    /**
     * Komplette Schaltuhr eines Kanals schreiben (die Doku verlangt immer den ganzen 90-Byte-Block).
     * $program 1..9 = Standardprogramm, sonst $text = eigenes Wochenprogramm.
     */
    private function WriteSchedule(string $prefix, int $channel, int $program, ?string $text): void
    {
        if ($text === null) {
            if ($program < 1 || $program > 9) {
                $this->LogMessage('Schaltprogramm ' . $program . ' nicht waehlbar', KL_WARNING);
                return;
            }
            $text = LogamaticData::STD_PROGRAMS[$program];
        }
        $days = LogamaticData::parseSchedule($text);
        if (is_string($days)) {
            $this->LogMessage('Schaltuhr ' . $prefix . ': ' . $days, KL_WARNING);
            $this->SV('Status', 'Schaltuhr ' . $prefix . ': ' . $days);
            return;
        }
        $type = LogamaticData::PT_KANAL[$channel];
        $par = json_decode($this->ReadAttributeString('Par'), true) ?: [];
        if ($prefix[0] === 'H') { // Heizkreis: Pause/Party/Ferien stehen als Variablen
            $meta = [(int) $this->GV($prefix . 'Pause'), (int) $this->GV($prefix . 'Party'), (int) $this->GV($prefix . 'Ferientage')];
        } else {
            $meta = [(int) ($par[$type][1] ?? 0), (int) ($par[$type][2] ?? 0), (int) ($par[$type][3] ?? 0)];
        }
        $block = LogamaticData::buildScheduleBlock($program, $meta[0], $meta[1], $meta[2], $days);
        $frames = [];
        for ($chunk = 0; $chunk < 15; $chunk++) {
            $frames[] = chr(0xB0) . chr($this->Addr()) . chr($type) . chr($chunk * 7) . substr($block, $chunk * 6, 6);
        }
        $old = [[$prefix . 'Schaltprogramm', $this->GV($prefix . 'Schaltprogramm'), true], [$prefix . 'Schaltuhr', $this->GV($prefix . 'Schaltuhr'), true]];
        $this->QueueJob(['kind' => 'schedule', 'steps' => $this->WriteSteps($frames), 'undo' => $old], true);

        $this->SV($prefix . 'Schaltprogramm', $program);
        $norm = LogamaticData::scheduleToText(array_values(array_map('ord', str_split($block))));
        $this->SV($prefix . 'Schaltuhr', $norm ?? $text);
        $par[$type] = array_map('ord', str_split($block));
        $this->WriteAttributeString('Par', json_encode($par));
        $this->LogMessage(sprintf('Schreibe Schaltuhr %s (Programm %d): %s', $prefix, $program, $norm ?? $text), KL_NOTIFY);
    }

    /** Gelesene Schaltuhrbloecke (dokumentiertes Layout) in Variablen uebernehmen. */
    private function SyncChannels(): void
    {
        $par = json_decode($this->ReadAttributeString('Par'), true) ?: [];
        $known = json_decode($this->ReadAttributeString('Known'), true) ?: [];
        foreach ($this->Channels() as $prefix => $channel) {
            $blk = $par[LogamaticData::PT_KANAL[$channel]] ?? null;
            if (!is_array($blk)) {
                continue;
            }
            $text = LogamaticData::scheduleToText($blk);
            if ($text === null) {
                continue;
            }
            $this->SV($prefix . 'Schaltprogramm', (int) $blk[0]);
            $this->SV($prefix . 'Schaltuhr', $text);
            if ($prefix[0] === 'H') {
                $this->SV($prefix . 'Pause', (int) $blk[1]);
                $this->SV($prefix . 'Party', (int) $blk[2]);
                $this->SV($prefix . 'Ferientage', (int) $blk[3]);
                $known = array_merge($known, [$prefix . 'Pause', $prefix . 'Party', $prefix . 'Ferientage']);
            }
        }
        $this->WriteAttributeString('Known', json_encode(array_values(array_unique($known))));
    }

    /**
     * Parameter ins Regelgeraet schreiben und Variable nachfuehren.
     * Ein Wert, der bereits bekannt und identisch ist, wird nicht geschrieben (Schreibzyklen sind begrenzt).
     */
    private function WriteParam(string $ident, $value, string $why): bool
    {
        $spec = $this->ParamTable()[$ident] ?? null;
        if ($spec === null || !is_numeric($value)) {
            return false;
        }
        $v = (float) $value;
        if ($v < $spec['min'] || $v > $spec['max']) {
            $this->LogMessage(sprintf('%s: Wert %s ausserhalb %s..%s', $ident, $value, $spec['min'], $spec['max']), KL_WARNING);
            return false;
        }
        $raw = (int) round($v * $spec['mul']);
        $cur = $this->GV($ident);
        $known = in_array($ident, json_decode($this->ReadAttributeString('Known'), true) ?: [], true);
        if ($known && abs((float) $cur - $v) < 0.001) {
            $this->Dbg('Schreiben uebersprungen (unveraendert): ' . $ident);
            return true;
        }
        $d = array_fill(0, 6, LogamaticData::KEEP);
        if (isset($spec['b2'])) { // Wert auf zwei Bytes: Hunderter und Rest
            $d[$spec['b'] - 1] = intdiv((int) round($v), 100);
            $d[$spec['b2'] - 1] = ((int) round($v)) % 100;
        } else {
            $d[$spec['b'] - 1] = $raw & 0xFF;
        }
        $frame = chr(0xB0) . chr($this->Addr()) . chr($spec['t']) . chr($spec['o']) . implode('', array_map('chr', $d));

        $this->QueueJob([
            'kind' => 'write',
            'steps' => $this->WriteSteps([$frame]),
            'undo' => [[$ident, $cur, $known]],
        ], true);
        $this->SV($ident, $v);
        $k =json_decode($this->ReadAttributeString('Known'), true) ?: [];
        if (!in_array($ident, $k, true)) {
            $k[] = $ident;
            $this->WriteAttributeString('Known', json_encode($k));
        }
        $this->LogMessage(sprintf('Schreibe %s = %s (%s)', $ident, $value, $why), KL_NOTIFY);
        return true;
    }

    // ------------------------------------------------------------------ Empfang

    public function ReceiveData($JSONString)
    {
        $d = json_decode($JSONString, true);
        $buf = (string) ($d['Buffer'] ?? '');
        $bin = L3964::fromJsonString($buf);
        if ($bin === '') {
            return '';
        }
        $this->Dbg('RAW RX ' . bin2hex($bin));
        $now = microtime(true);
        $this->Locked(function () use ($bin, $now) {
            $link = L3964::fromArray($this->st['link']);
            $res = $link->feed($bin, $now);
            $this->st['link'] = $link->toArray();
            $this->OnLinkResult($res, $now);
            foreach ($res['rx'] as $payload) {
                $this->HandleTelegram($payload);
            }
            $this->Engine($now);
        });
        return '';
    }

    private function OnLinkResult(array $res, float $now): void
    {
        $this->out .= $res['send'];
        $job = $this->st['job'] ?? null;
        if ($res['done'] && $job !== null && $job['ph'] === 'sending') {
            $step = $job['steps'][$job['pos']];
            if (($step['w'] ?? 'ack') === 'ack') {
                $this->StepDone();
            } else {
                $this->st['job']['ph'] = 'wait';
                $this->st['job']['dl'] = $now + (float) ($step['t'] ?? 15);
            }
        }
        if ($res['conflict'] && $job !== null && $job['ph'] === 'sending') {
            $this->st['job']['retry']++;
            $this->st['job']['ph'] = 'send';
            if ($this->st['job']['retry'] > 25) {
                $this->JobFail('Initialisierungskonflikt');
            }
        }
        if ($res['fail'] && $this->st['job'] !== null) {
            $this->JobFail('Keine Quittung vom Gateway (3964R)');
        }
    }

    private function HandleTelegram(string $p): void
    {
        if ($p === '') {
            return;
        }
        $this->Dbg('RX ' . bin2hex($p));
        $len = strlen($p);
        $c = ord($p[0]);
        $addr = $len > 1 ? ord($p[1]) : -1;

        if ($c === 0xAC || $c === 0xAA) { // Ende der Monitor- bzw. Parameterdaten
            $want = $c === 0xAC ? 'AC' : 'AA';
            $job = $this->st['job'] ?? null;
            if ($job !== null && $job['ph'] === 'wait' && ($job['steps'][$job['pos']]['w'] ?? '') === $want) {
                $this->StepDone();
            }
            if ($c === 0xAA && $addr === $this->Addr()) {
                $this->SyncChannels();
                $this->SyncParamVars();
            }
            return;
        }
        if ($addr !== $this->Addr()) {
            $this->Dbg('Telegramm fuer Adresse ' . $addr . ' ignoriert');
            return;
        }
        $this->WriteAttributeInteger('LastRx', time());
        $this->SV('LetzterEmpfang', time());

        switch ($c) {
            case 0xA7: // einzelner Monitorwert (Normal-Modus)
                if ($len >= 5) {
                    $this->StoreMonitor(ord($p[2]), ord($p[3]), [ord($p[4])], false);
                }
                break;
            case 0xAB: // Monitorblock (Direkt-Modus)
                if ($len >= 10) {
                    $this->StoreMonitor(ord($p[2]), ord($p[3]), array_values(unpack('C6', substr($p, 4, 6))), true);
                }
                break;
            case 0xA9: // einstellbare Parameter
                if ($len >= 10) {
                    $this->StoreParams(ord($p[2]), ord($p[3]), array_values(unpack('C6', substr($p, 4, 6))));
                }
                break;
            case 0xAE: // offene Fehler
                $codes = [];
                for ($i = 2; $i < min($len, 6); $i++) {
                    if (ord($p[$i]) !== 0) {
                        $codes[] = ord($p[$i]);
                    }
                }
                $this->UpdateErrors($codes);
                break;
            default:
                $this->Dbg('Unbekanntes Telegramm 0x' . dechex($c));
        }
    }

    /** Offset -> Byteindex: Bloecke tragen den Offset in 7er-Schritten (Doku "Einstellbare Parameter" 4.5), Einzelwerte direkt. */
    private function ToIndex(int $off, bool $block): int
    {
        if ($block && $off % 7 === 0) {
            return intdiv($off, 7) * 6;
        }
        return $off;
    }

    private function StoreMonitor(int $type, int $off, array $bytes, bool $block): void
    {
        $raw = json_decode($this->ReadAttributeString('Raw'), true) ?: [];
        $base = $this->ToIndex($off, $block);
        foreach ($bytes as $i => $b) {
            $raw[$type][$base + $i] = $b;
        }
        $this->WriteAttributeString('Raw', json_encode($raw));
        $this->ApplyType($type, $raw[$type]);
    }

    private function StoreParams(int $type, int $off, array $bytes): void
    {
        $par = json_decode($this->ReadAttributeString('Par'), true) ?: [];
        $base = intdiv($off, 7) * 6;
        foreach ($bytes as $i => $b) {
            $par[$type][$base + $i] = $b;
        }
        $this->WriteAttributeString('Par', json_encode($par));
    }

    // ------------------------------------------------------------------ Dekodierung -> Variablen

    private function ApplyType(int $type, array $bytes): void
    {
        $bytes = array_map('intval', $bytes);
        $set = function (string $prefix, array $vals) {
            foreach ($vals as $k => $v) {
                $ident = $prefix . $k;
                if ($prefix === 'Kessel_' && ($k === 'LaufzeitStufe1' || $k === 'LaufzeitStufe2')) {
                    $v = round($v / 60, 1);
                }
                $this->SV($ident, $v);
            }
        };
        foreach (LogamaticData::MT_HK as $n => $t) {
            if ($type === $t) {
                $set('HK' . $n . '_', LogamaticData::decodeHeizkreis($bytes));
                return;
            }
        }
        switch ($type) {
            case LogamaticData::MT_WW:
                $set('WW_', LogamaticData::decodeWarmwasser($bytes));
                break;
            case LogamaticData::MT_KESSEL:
                $set('Kessel_', LogamaticData::decodeKessel($bytes));
                break;
            case LogamaticData::MT_KONFIG:
                $set('', LogamaticData::decodeKonfig($bytes));
                break;
            case LogamaticData::MT_SOLAR:
                $set('Solar_', LogamaticData::decodeSolar($bytes));
                break;
        }
    }

    /** Gelesene Parameterbytes in die Variablen uebernehmen (nur wenn "Parameter lesen" aktiv). */
    private function SyncParamVars(): void
    {
        if (!$this->ReadPropertyBoolean('ReadParams')) {
            return;
        }
        $par = json_decode($this->ReadAttributeString('Par'), true) ?: [];
        $known = json_decode($this->ReadAttributeString('Known'), true) ?: [];
        foreach ($this->ParamTable() as $ident => $s) {
            $idx = intdiv($s['o'], 7) * 6 + $s['b'] - 1;
            if (!isset($par[$s['t']][$idx])) {
                continue;
            }
            $raw = (int) $par[$s['t']][$idx];
            if (isset($s['b2'])) {
                $idx2 = intdiv($s['o'], 7) * 6 + $s['b2'] - 1;
                if (!isset($par[$s['t']][$idx2])) {
                    continue;
                }
                $val = (float) ($raw * 100 + (int) $par[$s['t']][$idx2]);
            } else {
                if ($s['min'] < 0 && $raw > 127) {
                    $raw -= 256;
                }
                $val = round($raw / $s['mul'], 3);
            }
            if ($val < $s['min'] || $val > $s['max']) {
                $this->Dbg(sprintf('Parameter %s: gelesener Wert %s ausserhalb des Stellbereichs, verworfen', $ident, $val));
                continue;
            }
            $this->SV($ident, $val);
            if (!in_array($ident, $known, true)) {
                $known[] = $ident;
            }
        }
        $this->WriteAttributeString('Known', json_encode($known));
    }

    private function UpdateErrors(array $codes): void
    {
        $old = json_decode($this->ReadAttributeString('Errors'), true) ?: [];
        $new = array_values(array_unique($codes));
        $hist = json_decode($this->ReadAttributeString('History'), true) ?: [];
        $ts = date('d.m.Y H:i');
        foreach (array_diff($new, $old) as $c) {
            $line = $ts . '  KOMMT  ' . $c . ' ' . LogamaticData::errorText($c);
            array_unshift($hist, $line);
            $this->LogMessage($line, KL_ERROR);
        }
        foreach (array_diff($old, $new) as $c) {
            $line = $ts . '  GEHT   ' . $c . ' ' . LogamaticData::errorText($c);
            array_unshift($hist, $line);
            $this->LogMessage($line, KL_NOTIFY);
        }
        $hist = array_slice($hist, 0, 40);
        $this->WriteAttributeString('Errors', json_encode($new));
        $this->WriteAttributeString('History', json_encode($hist));
        $this->SV('StoerungAktiv', count($new) > 0);
        $this->SV('StoerungenText', $new ? implode("\n", array_map(fn ($c) => $c . ' ' . LogamaticData::errorText($c), $new)) : 'Keine Störung');
        $this->SV('StoerungHistorie', implode("\n", $hist));
    }

    // ------------------------------------------------------------------ Job-Engine

    private function NewState(): array
    {
        return ['link' => (new L3964())->toArray(), 'job' => null, 'queue' => []];
    }

    private function PollJob(bool $withParams): array
    {
        $a = chr($this->Addr());
        $steps = [
            ['p' => bin2hex("\xDD"), 'w' => 'ack'],
            ['p' => bin2hex("\xA2" . $a), 'w' => 'AC', 't' => 20],
        ];
        if ($withParams) { // liefert u.a. die dokumentierten Schaltuhr-Bloecke
            $steps[] = ['p' => bin2hex("\xA1" . $a), 'w' => 'AA', 't' => 30];
        }
        $steps[] = ['p' => bin2hex("\xDC"), 'w' => 'ack'];
        return ['kind' => 'poll', 'steps' => $steps, 'undo' => []];
    }

    /** Befehlsfolge fuer Schreibzugriffe: Direkt-Modus ein, Bloecke, Direkt-Modus aus. */
    private function WriteSteps(array $frames): array
    {
        $steps = [['p' => bin2hex("\xDD"), 'w' => 'ack']];
        foreach ($frames as $f) {
            $steps[] = ['p' => bin2hex($f), 'w' => 'ack'];
        }
        $steps[] = ['p' => bin2hex("\xDC"), 'w' => 'ack'];
        return $steps;
    }

    private function JobPending(string $kind): bool
    {
        if (($this->st['job']['kind'] ?? '') === $kind) {
            return true;
        }
        foreach ($this->st['queue'] as $j) {
            if ($j['kind'] === $kind) {
                return true;
            }
        }
        return false;
    }

    /** Nur mit gehaltenem Lock aufrufen. */
    private function Enqueue(array $job, bool $front): void
    {
        if (!$this->HasActiveParent()) {
            $this->Dbg('Job verworfen: keine aktive Verbindung');
            $this->RevertUndo($job['undo'] ?? []);
            return;
        }
        if ($job['kind'] === 'poll') {
            $this->WriteAttributeInteger('LastPoll', time());
        }
        if (count($this->st['queue']) > 30) {
            $this->Dbg('Job-Warteschlange voll');
            return;
        }
        if ($front) {
            array_unshift($this->st['queue'], $job);
        } else {
            $this->st['queue'][] = $job;
        }
    }

    private function QueueJob(array $job, bool $front): void
    {
        $this->Locked(function () use ($job, $front) {
            $this->Enqueue($job, $front);
            $this->Engine(microtime(true));
        });
    }

    private function Engine(float $now): void
    {
        for ($guard = 0; $guard < 20; $guard++) {
            if ($this->st['job'] === null) {
                if (!$this->st['queue']) {
                    return;
                }
                $this->st['job'] = array_shift($this->st['queue']);
                $this->st['job']['pos'] = 0;
                $this->st['job']['ph'] = 'send';
                $this->st['job']['retry'] = 0;
            }
            $job = $this->st['job'];
            if ($job['pos'] >= count($job['steps'])) {
                $this->st['job'] = null;
                continue;
            }
            switch ($job['ph']) {
                case 'send':
                    $link = L3964::fromArray($this->st['link']);
                    // erst senden, wenn das Gateway kurz ruhig ist: es sendet im Normal-Modus selbst und
                    // blockiert sonst mit eigenem STX (Initialisierungskonflikt)
                    if (!$link->isIdle() || $link->quietFor($now) < 0.4) {
                        return;
                    }
                    $this->out .= $link->send((string) hex2bin($job['steps'][$job['pos']]['p']), $now);
                    $this->st['link'] = $link->toArray();
                    $this->st['job']['ph'] = 'sending';
                    return;
                case 'wait':
                    if ($now > $job['dl']) {
                        $this->Dbg('Zeitueberschreitung beim Warten auf Endekennung, weiter');
                        $this->StepDone();
                        continue 2;
                    }
                    return;
                default:
                    return;
            }
        }
    }

    private function StepDone(): void
    {
        $this->st['job']['pos']++;
        $this->st['job']['ph'] = 'send';
        $this->st['job']['retry'] = 0;
        if ($this->st['job']['pos'] >= count($this->st['job']['steps'])) {
            if ($this->st['job']['kind'] === 'poll') {
                $this->SV('Status', 'Verbunden, letzte Abfrage ' . date('d.m.Y H:i:s'));
            }
            $this->st['job'] = null;
        }
    }

    private function JobFail(string $why): void
    {
        $job = $this->st['job'];
        $this->st['job'] = null;
        $this->LogMessage('Befehl "' . ($job['kind'] ?? '?') . '" fehlgeschlagen: ' . $why, KL_ERROR);
        $this->SV('Status', 'Fehler: ' . $why);
        $this->RevertUndo($job['undo'] ?? []);
    }

    private function RevertUndo(array $undo): void
    {
        foreach ($undo as [$ident, $old, $wasKnown]) {
            $this->SV($ident, $old);
            if (!$wasKnown) {
                $k = array_values(array_diff(json_decode($this->ReadAttributeString('Known'), true) ?: [], [$ident]));
                $this->WriteAttributeString('Known', json_encode($k));
            }
        }
    }

    private function Locked(callable $fn)
    {
        $key = 'BLM_' . $this->InstanceID;
        if (!IPS_SemaphoreEnter($key, 5000)) {
            $this->LogMessage('Zugriffssperre nicht erhalten', KL_WARNING);
            return null;
        }
        try {
            $raw = $this->GetBuffer('ST');
            $st = $raw !== '' ? json_decode($raw, true) : null;
            $this->st = is_array($st) ? $st : $this->NewState();
            $this->out = '';
            $r = $fn();
            $this->SetBuffer('ST', json_encode($this->st));
            if ($this->out !== '') {
                $this->Dbg('TX ' . bin2hex($this->out));
                $this->SendDataToParent(json_encode([
                    'DataID' => self::DATA_TO_PARENT,
                    'Buffer' => L3964::toJsonString($this->out),
                ]));
                $this->out = '';
            }
            return $r;
        } finally {
            IPS_SemaphoreLeave($key);
        }
    }

    private function Addr(): int
    {
        return $this->ReadPropertyInteger('Address');
    }

    private function Dbg(string $msg): void
    {
        if ($this->ReadPropertyBoolean('Debug')) {
            $this->SendDebug('Logamatic', $msg, 0);
            $t = json_decode($this->ReadAttributeString('Trace'), true) ?: [];
            array_unshift($t, date('H:i:s') . ' ' . $msg);
            $this->WriteAttributeString('Trace', json_encode(array_slice($t, 0, 60)));
        }
    }

    /** Telegrammprotokoll-Variable aus dem Ringpuffer fuellen (nur bei aktivem Debug). */
    private function SyncTrace(): void
    {
        if ($this->ReadPropertyBoolean('Debug')) {
            $this->SV('Telegramprotokoll', implode("\n", json_decode($this->ReadAttributeString('Trace'), true) ?: []));
        }
    }

    // ------------------------------------------------------------------ Uhr

    private function ClockFrame(): string
    {
        $t = time();
        $hour = (int) date('G', $t) | (date('I', $t) === '1' ? 0x40 : 0);  // Bit 7 = Sommerzeit
        $month = (int) date('n', $t) | (((int) date('N', $t)) << 4);        // Bit 5..7 = Wochentag (Mo=1)
        $year = (int) date('Y', $t) - 2000 + 100;                            // >100 bedeutet 20xx
        return chr(0xB0) . chr($this->Addr()) . chr(LogamaticData::PT_DATUM) . chr(0x3F)
            . chr((int) date('s', $t)) . chr((int) date('i', $t)) . chr($hour)
            . chr((int) date('j', $t)) . chr($month) . chr($year);
    }

    private function DailyClock(): void
    {
        if ($this->ReadPropertyBoolean('ClockSyncDaily') && (int) date('G') === 3
            && time() - $this->ReadAttributeInteger('LastClockSync') > 20 * 3600 && $this->HasActiveParent()) {
            $this->SyncClock();
        }
    }

    // ------------------------------------------------------------------ Legionellen-Automatik

    private function LegiTemp(): ?float
    {
        $src = $this->ReadPropertyInteger('LegiSourceVar');
        if ($src > 0 && IPS_VariableExists($src)) {
            return (float) GetValue($src);
        }
        $raw = json_decode($this->ReadAttributeString('Raw'), true) ?: [];
        $v = $raw[LogamaticData::MT_WW][3] ?? null;
        return $v === null ? null : (float) $v;
    }

    private function DataFresh(): bool
    {
        if (!$this->HasActiveParent()) {
            return false;
        }
        if ($this->ReadPropertyInteger('PollMinutes') <= 0) {
            return true;
        }
        $max = max(15, 3 * $this->ReadPropertyInteger('PollMinutes')) * 60;
        return time() - $this->ReadAttributeInteger('LastRx') <= $max;
    }

    /**
     * Naechster Desinfektionstermin (Zeitstempel) nach $now. $day: 0=Mo .. 6=So, 7=taeglich.
     * Liefert [vorheriger Termin, naechster Termin].
     */
    private function Occurrences(int $now, int $day, int $hour): array
    {
        $prev = 0;
        $next = 0;
        for ($i = -8; $i <= 8; $i++) {
            $t = mktime($hour, 0, 0, (int) date('n', $now), (int) date('j', $now) + $i, (int) date('Y', $now));
            if ($day !== 7 && ((int) date('N', $t) - 1) !== $day) {
                continue;
            }
            if ($t <= $now) {
                $prev = $t;
            } elseif ($next === 0) {
                $next = $t;
            }
        }
        return [$prev, $next];
    }

    private function LegiStatus(string $s): void
    {
        $this->SV('Legi_Status', $s);
    }

    private function LegiTick(): void
    {
        if (!$this->GroupEnabled('LEGI')) {
            return;
        }
        $now = time();

        // 1) Heisse Phase erfassen: Mindesttemperatur fuer Mindestdauer
        $temp = $this->LegiTemp();
        $min = $this->ReadPropertyInteger('LegiMinTemp');
        if ($temp !== null && $this->DataFresh() && $temp >= $min) {
            $since = $this->ReadAttributeInteger('LegiHotSince');
            if ($since === 0) {
                $since = $now;
                $this->WriteAttributeInteger('LegiHotSince', $since);
            }
            if ($now - $since >= $this->ReadPropertyInteger('LegiHoldMinutes') * 60) {
                $this->WriteAttributeInteger('LegiLast', $now);
                $this->SV('Legi_LetzteErhitzung', $now);
            }
        } elseif ($this->ReadAttributeInteger('LegiHotSince') !== 0) {
            $this->WriteAttributeInteger('LegiHotSince', 0);
        }
        if ($this->GV('Legi_LetzteErhitzung') === 0 && $this->ReadAttributeInteger('LegiLast') > 0) {
            $this->SV('Legi_LetzteErhitzung', $this->ReadAttributeInteger('LegiLast'));
        }

        // 2) Entscheidung
        if (!$this->GV('Legi_Aktiv')) {
            $this->LegiStatus('Automatik ausgeschaltet');
            return;
        }
        $known = json_decode($this->ReadAttributeString('Known'), true) ?: [];
        foreach (['WW_Desinfektion', 'WW_DesinfTag', 'WW_DesinfStunde'] as $need) {
            if (!in_array($need, $known, true)) {
                $this->LegiStatus('Warte: Desinfektions-Einstellungen (Ein/Aus, Tag, Stunde) einmal setzen oder lesen');
                return;
            }
        }
        if (!$this->HasActiveParent()) {
            $this->LegiStatus('Keine Verbindung zum Gateway');
            return;
        }
        $en = (bool) $this->GV('WW_Desinfektion');
        $day = (int) $this->GV('WW_DesinfTag');
        $hour = (int) $this->GV('WW_DesinfStunde');
        $supp = $this->ReadAttributeBoolean('LegiSuppressed');
        if ($supp && $en) {
            $this->WriteAttributeBoolean('LegiSuppressed', false);
            $supp = false;
        }
        [$prev, $next] = $this->Occurrences($now, $day, $hour);
        $this->SV('Legi_NaechsterTermin', $next);
        $this->SV('Legi_Ausgesetzt', $supp);

        // Sicherheitsnetz: nach dem Termin wieder scharf schalten
        if ($supp && !$en && $now >= $this->ReadAttributeInteger('LegiSuppressedFor') + 2 * 3600) {
            if ($this->WriteParam('WW_Desinfektion', 1, 'Legionellen-Automatik: Desinfektion wieder freigegeben')) {
                $this->LegiStatus('Desinfektion wieder freigegeben');
            }
            return;
        }
        if ($this->GV('WW_DesinfektionAktiv')) {
            $this->LegiStatus('Desinfektion läuft');
            return;
        }

        $last = $this->ReadAttributeInteger('LegiLast');
        $window = $this->ReadPropertyInteger('LegiHoursBefore') * 3600;
        $qualified = $last > 0 && $last >= $next - $this->ReadPropertyInteger('LegiValidDays') * 86400;
        $inWindow = $now >= $next - $window && $now < $next;
        $when = date('d.m. H:i', $next);

        if (!$en && !$supp) {
            $this->LegiStatus('Desinfektion im Regelgerät ausgeschaltet (manuell)');
        } elseif ($supp) {
            $this->LegiStatus('Ausgesetzt für ' . date('d.m. H:i', $this->ReadAttributeInteger('LegiSuppressedFor')) . ' (Wasser war heiß genug)');
        } elseif ($inWindow && $qualified) {
            if ($this->WriteParam('WW_Desinfektion', 0, 'Legionellen-Automatik: Wasser war zuletzt ' . date('d.m. H:i', $last) . ' heiß genug')) {
                $this->WriteAttributeBoolean('LegiSuppressed', true);
                $this->WriteAttributeInteger('LegiSuppressedFor', $next);
                $this->SV('Legi_Ausgesetzt', true);
                $this->LegiStatus('Ausgesetzt für ' . $when . ' (Wasser war heiß genug)');
            }
        } elseif ($qualified) {
            $this->LegiStatus('Nächster Termin ' . $when . ': wird voraussichtlich ausgesetzt (zuletzt heiß ' . date('d.m. H:i', $last) . ')');
        } else {
            $this->LegiStatus('Nächster Termin ' . $when . ': Desinfektion findet statt');
        }
    }

    // ------------------------------------------------------------------ Variablen / Parameter

    private function GroupEnabled(string $g): bool
    {
        switch ($g) {
            case 'ALLG':
                return true;
            case 'LEGI':
                return $this->ReadPropertyBoolean('WW');
            default:
                return $this->ReadPropertyBoolean($g);
        }
    }

    /** Parameter-Definitionen: ident => [t=Datentyp, o=Offset, b=Byte 1..6, min, max, mul (Rohwert = Wert*mul)]. */
    private function ParamTable(): array
    {
        $P = function (int $t, int $o, int $b, float $min, float $max, float $mul = 1, ?int $b2 = null) {
            $s = ['t' => $t, 'o' => $o, 'b' => $b, 'min' => $min, 'max' => $max, 'mul' => $mul];
            if ($b2 !== null) {
                $s['b2'] = $b2;
            }
            return $s;
        };
        $tab = [];
        foreach (LogamaticData::PT_HK as $n => $ty) {
            if (!$this->ReadPropertyBoolean('HK' . $n)) {
                continue;
            }
            $p = 'HK' . $n . '_';
            $tab[$p . 'Schwelle'] = $P($ty, 0x00, 2, 9, 31);
            $tab[$p . 'Nacht'] = $P($ty, 0x00, 3, 2, 30, 2);
            $tab[$p . 'Tag'] = $P($ty, 0x00, 4, 10, 30, 2);
            $tab[$p . 'Betriebsart'] = $P($ty, 0x00, 5, 0, 2);
            $tab[$p . 'FerienArt'] = $P($ty, 0x3F, 1, 0, 3);
            $tab[$p . 'FerienAussenhalt'] = $P($ty, 0x3F, 2, -20, 10);
            $tab[$p . 'Auslegung'] = $P($ty, 0x0E, 5, 30, 90);
            $tab[$p . 'Heizsystem'] = $P($ty, 0x38, 2, 0, 7);
            $kanal = LogamaticData::PT_KANAL[$n];
            $tab[$p . 'Pause'] = $P($kanal, 0x00, 2, 0, 99);
            $tab[$p . 'Party'] = $P($kanal, 0x00, 3, 0, 99);
            $tab[$p . 'Ferientage'] = $P($kanal, 0x00, 4, 0, 99);
        }
        if ($this->ReadPropertyBoolean('WW')) {
            $ty = LogamaticData::PT_WW;
            $tab['WW_Desinfektion'] = $P($ty, 0x00, 2, 0, 1);
            $tab['WW_DesinfTemp'] = $P($ty, 0x00, 3, 65, 75);
            $tab['WW_DesinfTag'] = $P($ty, 0x00, 4, 0, 7);
            $tab['WW_DesinfStunde'] = $P($ty, 0x00, 5, 0, 23);
            $tab['WW_Sollwert'] = $P($ty, 0x07, 4, 30, 60);
            $tab['WW_Betriebsart'] = $P($ty, 0x0E, 1, 0, 2);
            $tab['WW_ZirkLaeufe'] = $P($ty, 0x0E, 5, 0, 7);
            $tab['WW_ZirkBetriebsart'] = $P($ty, 0x15, 2, 0, 2);
            $tab['WW_TaeglAufheizung'] = $P($ty, 0x2A, 3, 0, 24);
        }
        if ($this->ReadPropertyBoolean('Kessel')) {
            $ty = LogamaticData::PT_KESSEL;
            $tab['Kessel_Abgasgrenze'] = $P($ty, 0x07, 6, 45, 250, 0.2);
            $tab['Kessel_Nachtabsenkung'] = $P($ty, 0x46, 4, 0, 40);
            $tab['Kessel_Betriebsart'] = $P($ty, 0x46, 5, 0, 2);
            $tab['Kessel_Lastbegrenzung'] = $P($ty, 0x3F, 4, -31, 31);
            $tab['Kessel_LeistungGasMax'] = $P($ty, 0x4D, 1, 0, 9999, 1, 2);
            $tab['Kessel_LeistungGasMin'] = $P($ty, 0x4D, 3, 0, 9999, 1, 4);
            $tab['Kessel_LeistungOelMax'] = $P($ty, 0x4D, 5, 0, 9999, 1, 6);
            $tab['Kessel_LeistungOelMin'] = $P($ty, 0x54, 1, 0, 9999, 1, 2);
            $tab['Kessel_KorrekturX'] = $P($ty, 0x54, 4, 0, 100);
            $tab['Kessel_KorrekturY'] = $P($ty, 0x54, 5, 0, 100);
        }
        if ($this->ReadPropertyBoolean('Strat447')) {
            $ty = 0x20;
            $tab['S447_Kesselfolge'] = $P($ty, 0x00, 3, 1, 6);
            $tab['S447_Folge1'] = $P($ty, 0x00, 4, 1, 6);
            $tab['S447_Folge2'] = $P($ty, 0x00, 5, 1, 6);
            $tab['S447_Folge3'] = $P($ty, 0x00, 6, 1, 6);
            $tab['S447_ATGrenze1'] = $P($ty, 0x1C, 3, -30, 30);
            $tab['S447_ATGrenze2'] = $P($ty, 0x1C, 4, -30, 30);
            $tab['S447_GesperrteKessel'] = $P($ty, 0x15, 2, 1, 3);
            $tab['S447_LastbegrAT1'] = $P($ty, 0x15, 3, -31, 30);
            $tab['S447_LastbegrAT2'] = $P($ty, 0x15, 4, -31, 30);
        }
        if ($this->ReadPropertyBoolean('StratUBA')) {
            $ty = 0x0E;
            $tab['UBA_Folgeumkehr'] = $P($ty, 0x00, 4, 0, 1);
            $tab['UBA_Anhebung'] = $P($ty, 0x07, 1, 0, 30);
        }
        if ($this->ReadPropertyBoolean('StratFM458')) {
            $ty = 0x26;
            $tab['S458_KesselExtern'] = $P($ty, 0x00, 6, 1, 8);
            $tab['S458_Schwelle1'] = $P($ty, 0x07, 1, -31, 30);
            $tab['S458_Schwelle2'] = $P($ty, 0x07, 2, -31, 30);
            $tab['S458_MaxKessel'] = $P($ty, 0x07, 3, 0, 8);
            $tab['S458_FolgeA'] = $P($ty, 0x07, 6, 0, 39);
            $tab['S458_FolgeB'] = $P($ty, 0x0E, 1, 0, 39);
            $tab['S458_FolgeC'] = $P($ty, 0x0E, 2, 0, 39);
            $tab['S458_FolgeD'] = $P($ty, 0x0E, 3, 0, 39);
            $tab['S458_UmschaltA'] = $P($ty, 0x0E, 4, -20, 30);
            $tab['S458_UmschaltB'] = $P($ty, 0x0E, 5, -30, 30);
            $tab['S458_UmschaltC'] = $P($ty, 0x0E, 6, -30, 30);
        }
        if ($this->ReadPropertyBoolean('Solar')) {
            $ty = LogamaticData::PT_SOLAR;
            $tab['Solar_Betriebsart'] = $P($ty, 0x00, 4, 0, 2);
            $tab['Solar_Verbraucher'] = $P($ty, 0x00, 6, 0, 2);
            $tab['Solar_MaxSp1'] = $P($ty, 0x07, 1, 30, 90);
            $tab['Solar_MinSp1'] = $P($ty, 0x07, 2, 30, 55);
            $tab['Solar_MaxSp2'] = $P($ty, 0x0E, 4, 20, 90);
            $tab['Solar_Glykol'] = $P($ty, 0x1C, 1, 20, 50, 0.1);
        }
        return $tab;
    }

    /** @return array<string, int> aktive Variablen ident => Typ */
    private function ActiveVars(): array
    {
        if ($this->defCache === null) {
            $this->defCache = [];
            foreach ($this->Defs() as $d) {
                if ($this->GroupEnabled($d[0])) {
                    $this->defCache[$d[1]] = $d[3];
                }
            }
        }
        return $this->defCache;
    }

    /** Variable setzen, nur wenn sie existiert und sich der Wert aendert. */
    private function SV(string $ident, $value): void
    {
        $vars = $this->ActiveVars();
        if (!isset($vars[$ident]) || $value === null) {
            return;
        }
        switch ($vars[$ident]) {
            case self::T_BOOL:
                $value = (bool) $value;
                break;
            case self::T_INT:
                $value = (int) round((float) $value);
                break;
            case self::T_FLOAT:
                $value = (float) $value;
                break;
            default:
                $value = (string) $value;
        }
        $this->SetV($ident, $value);
    }

    // ------------------------------------------------------------------ Variablen-Zugriff (Variablen liegen in Unterkategorien)

    /**
     * IPSModule::GetIDForIdent/SetValue finden nur direkte Kinder der Instanz. Da die Variablen in
     * Kategorien unterhalb der Instanz liegen, werden die IDs hier selbst aufgeloest.
     *
     * @return array<string,int> Ident => Objekt-ID (Variablen und Kategorien)
     */
    private function IdMap(): array
    {
        if ($this->idMap === null) {
            $this->idMap = [];
            foreach (IPS_GetChildrenIDs($this->InstanceID) as $c) {
                $o = IPS_GetObject($c);
                if ($o['ObjectIdent'] !== '') {
                    $this->idMap[$o['ObjectIdent']] = $c;
                }
                if ($o['ObjectType'] === 0) { // Kategorie
                    foreach (IPS_GetChildrenIDs($c) as $cc) {
                        $oc = IPS_GetObject($cc);
                        if ($oc['ObjectIdent'] !== '') {
                            $this->idMap[$oc['ObjectIdent']] = $cc;
                        }
                    }
                }
            }
        }
        return $this->idMap;
    }

    private function GV(string $ident)
    {
        $id = $this->IdMap()[$ident] ?? 0;
        return $id > 0 ? GetValue($id) : null;
    }

    private function SetV(string $ident, $value): void
    {
        $id = $this->IdMap()[$ident] ?? 0;
        if ($id > 0 && GetValue($id) !== $value) {
            SetValue($id, $value);
        }
    }

    /** Kategorie (Schluessel, Name, Position) fuer eine Variable, null = direkt unter der Instanz. */
    private function CategoryOf(string $group, string $ident): ?array
    {
        if ($group === 'ALLG') {
            if (in_array($ident, ['StoerungAktiv', 'StoerungenText', 'StoerungHistorie'], true)) {
                return ['stoerungen', 'Störungen', 90];
            }
            return $ident === 'Telegramprotokoll' ? ['diagnose', 'Diagnose', 99] : null;
        }
        if (preg_match('/^HK(\d)$/', $group, $m)) {
            return [strtolower($group), $m[1] === '5' ? 'Heizkreis 5 / HK0 (Kesselkreis)' : 'Heizkreis ' . $m[1], 10 + (int) $m[1]];
        }
        $names = [
            'WW' => ['warmwasser', 'Warmwasser', 20], 'LEGI' => ['legionellen', 'Legionellen-Automatik', 25],
            'Kessel' => ['kessel', 'Kessel / Brenner', 30], 'Solar' => ['solar', 'Solar', 40],
            'Strat447' => ['strat447', 'Strategie FM447', 50], 'StratUBA' => ['stratuba', 'Strategie UBA', 51],
            'StratFM458' => ['stratfm458', 'Strategie FM458', 52],
        ];
        return $names[$group] ?? null;
    }

    /** Kategorie unterhalb der Instanz anlegen/holen. Liefert die Instanz-ID, falls Kategorien dort nicht moeglich sind. */
    private function EnsureCategory(array $cat): int
    {
        [$key, $name, $pos] = $cat;
        $ident = 'cat_' . $key;
        $id = $this->IdMap()[$ident] ?? 0;
        if ($id === 0) {
            $id = IPS_CreateCategory();
            IPS_SetIdent($id, $ident);
            @IPS_SetParent($id, $this->InstanceID);
            if (IPS_GetObject($id)['ParentID'] !== $this->InstanceID) {
                IPS_DeleteCategory($id);
                return $this->InstanceID;
            }
            $this->idMap = null;
        }
        if (IPS_GetName($id) !== $name) {
            IPS_SetName($id, $name);
        }
        IPS_SetPosition($id, $pos);
        return $id;
    }

    /** Variablendefinitionen: [Gruppe, Ident, Name, Typ, Profil, bedienbar, Position]. */
    private function Defs(): array
    {
        $B = self::T_BOOL;
        $I = self::T_INT;
        $F = self::T_FLOAT;
        $S = self::T_STRING;
        $T = '~Temperature';
        $defs = [];
        $pos = 0;
        $add = function (string $g, string $id, string $name, int $type, string $prof = '', bool $act = false) use (&$defs, &$pos) {
            $defs[] = [$g, $id, trim($name), $type, $prof, $act, $pos++];
        };

        $add('ALLG', 'Status', 'Status', $S);
        $add('ALLG', 'LetzterEmpfang', 'Letzter Empfang', $I, '~UnixTimestamp');
        $add('ALLG', 'Aussentemperatur', 'Außentemperatur', $F, $T);
        $add('ALLG', 'AussentemperaturGedaempft', 'Außentemperatur gedämpft', $F, $T);
        $add('ALLG', 'AnlagenVorlaufSoll', 'Anlagenvorlauf Soll', $F, $T);
        $add('ALLG', 'AnlagenVorlaufIst', 'Anlagenvorlauf Ist', $F, $T);
        $add('ALLG', 'Version', 'Regelgerät Version', $S);
        $add('ALLG', 'StoerungAktiv', 'Störung aktiv', $B, '~Alert');
        $add('ALLG', 'StoerungenText', 'Offene Störungen', $S);
        $add('ALLG', 'StoerungHistorie', 'Störungshistorie', $S);
        $add('ALLG', 'Telegramprotokoll', 'Telegrammprotokoll (nur bei Debug)', $S);

        foreach ([1, 2, 3, 4, 5] as $n) {
            $g = 'HK' . $n;
            $p = $g . '_';
            $nm = ''; // der Heizkreis steht im Kategorienamen
            $add($g, $p . 'Betriebsart', $nm . ' Betriebsart', $I, 'BLM.Betriebsart', true);
            $add($g, $p . 'Tag', $nm . ' Tagtemperatur', $F, 'BLM.TempTag', true);
            $add($g, $p . 'Nacht', $nm . ' Nachttemperatur', $F, 'BLM.TempNacht', true);
            $add($g, $p . 'Schwelle', $nm . ' Sommer/Winter-Schwelle', $I, 'BLM.Schwelle', true);
            $add($g, $p . 'RaumSoll', $nm . ' Raum Soll', $F, $T);
            $add($g, $p . 'RaumIst', $nm . ' Raum Ist', $F, $T);
            $add($g, $p . 'VorlaufSoll', $nm . ' Vorlauf Soll', $F, $T);
            $add($g, $p . 'VorlaufIst', $nm . ' Vorlauf Ist', $F, $T);
            $add($g, $p . 'Pumpe', $nm . ' Pumpe', $I, 'BLM.Prozent');
            $add($g, $p . 'Stellglied', $nm . ' Mischer', $I, 'BLM.Prozent');
            $add($g, $p . 'Sommer', $nm . ' Sommerbetrieb', $B, '~Switch');
            $add($g, $p . 'Tagbetrieb', $nm . ' Tagbetrieb', $B, '~Switch');
            $add($g, $p . 'Ferien', $nm . ' Ferienbetrieb', $B, '~Switch');
            $add($g, $p . 'Frostschutz', $nm . ' Frostschutz', $B, '~Switch');
            $add($g, $p . 'PartyPause', $nm . ' Party/Pause aktiv', $B, '~Switch');
            $add($g, $p . 'FehlerVorlauffuehler', $nm . ' Fehler Vorlauffühler', $B, '~Alert');
            $add($g, $p . 'Party', $nm . ' Party (Stunden)', $I, 'BLM.Stunden99', true);
            $add($g, $p . 'Pause', $nm . ' Pause (Stunden)', $I, 'BLM.Stunden99', true);
            $add($g, $p . 'Ferientage', $nm . ' Ferientage', $I, 'BLM.Tage99', true);
            $add($g, $p . 'FerienArt', $nm . ' Absenkart Ferien', $I, 'BLM.FerienArt', true);
            $add($g, $p . 'FerienAussenhalt', $nm . ' Ferien Außenhalt-Temperatur', $I, 'BLM.Aussenhalt', true);
            $add($g, $p . 'Auslegung', $nm . ' Auslegungstemperatur', $I, 'BLM.Auslegung', true);
            $add($g, $p . 'Heizsystem', $nm . ' Heizsystem', $I, 'BLM.Heizsystem', true);
            $add($g, $p . 'Schaltprogramm', $nm . ' Schaltprogramm', $I, 'BLM.Schaltprogramm', true);
            $add($g, $p . 'Schaltuhr', $nm . ' Schaltuhr (Wochenprogramm)', $S, '', true);
        }

        $add('WW', 'WW_Ist', 'Warmwasser Ist', $F, $T);
        $add('WW', 'WW_Soll', 'Warmwasser Soll (aktuell)', $F, $T);
        $add('WW', 'WW_Sollwert', 'Warmwasser Solltemperatur', $I, 'BLM.WWSoll', true);
        $add('WW', 'WW_Betriebsart', 'Warmwasser Betriebsart', $I, 'BLM.Betriebsart', true);
        $add('WW', 'WW_ZirkBetriebsart', 'Zirkulation Betriebsart', $I, 'BLM.Betriebsart', true);
        $add('WW', 'WW_ZirkLaeufe', 'Zirkulation Läufe pro Stunde', $I, 'BLM.ZirkLaeufe', true);
        $add('WW', 'WW_TaeglAufheizung', 'Tägliche Aufheizung ab (24 = aus)', $I, 'BLM.Stunde24', true);
        $add('WW', 'WW_Schaltprogramm', 'Warmwasser Schaltprogramm', $I, 'BLM.Schaltprogramm', true);
        $add('WW', 'WW_Schaltuhr', 'Warmwasser Schaltuhr (Wochenprogramm)', $S, '', true);
        $add('WW', 'WW_ZirkSchaltprogramm', 'Zirkulation Schaltprogramm', $I, 'BLM.Schaltprogramm', true);
        $add('WW', 'WW_ZirkSchaltuhr', 'Zirkulation Schaltuhr (Wochenprogramm)', $S, '', true);
        $add('WW', 'WW_Desinfektion', 'Thermische Desinfektion', $B, '~Switch', true);
        $add('WW', 'WW_DesinfTemp', 'Desinfektionstemperatur', $I, 'BLM.DesinfTemp', true);
        $add('WW', 'WW_DesinfTag', 'Desinfektionstag', $I, 'BLM.Desinfektionstag', true);
        $add('WW', 'WW_DesinfStunde', 'Desinfektionsstunde', $I, 'BLM.Stunde23', true);
        $add('WW', 'WW_DesinfektionAktiv', 'Desinfektion läuft', $B, '~Switch');
        $add('WW', 'WW_Laden', 'Speicherladung', $B, '~Switch');
        $add('WW', 'WW_Nachladung', 'Nachladung', $B, '~Switch');
        $add('WW', 'WW_Warm', 'Warmwasser warm', $B, '~Switch');
        $add('WW', 'WW_Ladepumpe', 'Ladepumpe', $B, '~Switch');
        $add('WW', 'WW_Zirkulationspumpe', 'Zirkulationspumpe', $B, '~Switch');
        $add('WW', 'WW_FehlerDesinfektion', 'Fehler Desinfektion', $B, '~Alert');
        $add('WW', 'WW_FehlerFuehler', 'Fehler Warmwasserfühler', $B, '~Alert');
        $add('WW', 'WW_FehlerBleibtKalt', 'Fehler Warmwasser bleibt kalt', $B, '~Alert');

        $add('LEGI', 'Legi_Aktiv', 'Legionellen-Automatik', $B, '~Switch', true);
        $add('LEGI', 'Legi_Status', 'Legionellen-Status', $S);
        $add('LEGI', 'Legi_LetzteErhitzung', 'Zuletzt ausreichend heiß', $I, '~UnixTimestamp');
        $add('LEGI', 'Legi_NaechsterTermin', 'Nächster Desinfektionstermin', $I, '~UnixTimestamp');
        $add('LEGI', 'Legi_Ausgesetzt', 'Desinfektion ausgesetzt', $B, '~Switch');

        $add('Kessel', 'Kessel_VorlaufIst', 'Kessel Vorlauf Ist', $F, $T);
        $add('Kessel', 'Kessel_VorlaufSoll', 'Kessel Vorlauf Soll', $F, $T);
        $add('Kessel', 'Kessel_RuecklaufSoll', 'Kessel Rücklauf Soll', $F, $T);
        $add('Kessel', 'Kessel_Abgastemperatur', 'Abgastemperatur', $F, $T);
        $add('Kessel', 'Kessel_BrennerStufe', 'Brenner', $I, 'BLM.Brennerstufe');
        $add('Kessel', 'Kessel_Kesselpumpe', 'Kesselpumpe', $I, 'BLM.Prozent');
        $add('Kessel', 'Kessel_LaufzeitStufe1', 'Brennerlaufzeit Stufe 1', $F, 'BLM.Stunden');
        $add('Kessel', 'Kessel_LaufzeitStufe2', 'Brennerlaufzeit Stufe 2', $F, 'BLM.Stunden');
        $add('Kessel', 'Kessel_StartsStufe1', 'Brennerstarts Stufe 1', $I);
        $add('Kessel', 'Kessel_StartsStufe2', 'Brennerstarts Stufe 2', $I);
        $add('Kessel', 'Kessel_Betriebsart', 'Kesselkreis Betriebsart', $I, 'BLM.Betriebsart', true);
        $add('Kessel', 'Kessel_Nachtabsenkung', 'Kesselkennlinie Nachtabsenkung', $I, 'BLM.Nachtabsenkung', true);
        $add('Kessel', 'Kessel_Abgasgrenze', 'Abgastemperatur-Grenze', $I, 'BLM.Abgasgrenze', true);
        $add('Kessel', 'Kessel_Lastbegrenzung', 'Lastbegrenzung 2x1-stufiger Brenner (31 = keine)', $I, 'BLM.Lastbegr', true);
        $add('Kessel', 'Kessel_LeistungGasMax', 'Brennerleistung Gas max.', $I, 'BLM.kW', true);
        $add('Kessel', 'Kessel_LeistungGasMin', 'Brennerleistung Gas min.', $I, 'BLM.kW', true);
        $add('Kessel', 'Kessel_LeistungOelMax', 'Brennerleistung Öl max.', $I, 'BLM.kW', true);
        $add('Kessel', 'Kessel_LeistungOelMin', 'Brennerleistung Öl min.', $I, 'BLM.kW', true);
        $add('Kessel', 'Kessel_KorrekturX', 'Linearisierung Brennerstellglied Punkt X', $I, 'BLM.Prozent', true);
        $add('Kessel', 'Kessel_KorrekturY', 'Linearisierung Brennerstellglied Punkt Y', $I, 'BLM.Prozent', true);
        $add('Kessel', 'Kessel_Abgastest', 'Abgastest', $B, '~Switch');
        $add('Kessel', 'Kessel_FehlerBrenner', 'Fehler Brennerstörung', $B, '~Alert');
        $add('Kessel', 'Kessel_FehlerKesselfuehler', 'Fehler Kesselfühler', $B, '~Alert');
        $add('Kessel', 'Kessel_FehlerKesselKalt', 'Fehler Kessel bleibt kalt', $B, '~Alert');
        $add('Kessel', 'Kessel_FehlerAbgasfuehler', 'Fehler Abgasfühler', $B, '~Alert');
        $add('Kessel', 'Kessel_FehlerAbgasGrenze', 'Fehler Abgasgrenze', $B, '~Alert');
        $add('Kessel', 'Kessel_FehlerSicherheitskette', 'Fehler Sicherheitskette', $B, '~Alert');
        $add('Kessel', 'Kessel_FehlerExtern', 'Fehler extern', $B, '~Alert');

        $add('Strat447', 'S447_Kesselfolge', 'Strategie FM447 manuelle Kesselfolge', $I, 'BLM.Kesselfolge', true);
        $add('Strat447', 'S447_Folge1', 'Strategie FM447 Kesselfolge 1 (Umkehr)', $I, 'BLM.Kesselfolge', true);
        $add('Strat447', 'S447_Folge2', 'Strategie FM447 Kesselfolge 2 (Umkehr)', $I, 'BLM.Kesselfolge', true);
        $add('Strat447', 'S447_Folge3', 'Strategie FM447 Kesselfolge 3 (Umkehr)', $I, 'BLM.Kesselfolge', true);
        $add('Strat447', 'S447_ATGrenze1', 'Strategie FM447 Außentemperaturgrenze 1', $I, 'BLM.TempM30_30', true);
        $add('Strat447', 'S447_ATGrenze2', 'Strategie FM447 Außentemperaturgrenze 2', $I, 'BLM.TempM30_30', true);
        $add('Strat447', 'S447_GesperrteKessel', 'Strategie FM447 gesperrte Kessel (ext. Lastbegrenzung)', $I, 'BLM.Anzahl1_3', true);
        $add('Strat447', 'S447_LastbegrAT1', 'Strategie FM447 Lastbegrenzung Außentemperatur 1', $I, 'BLM.TempM31_30', true);
        $add('Strat447', 'S447_LastbegrAT2', 'Strategie FM447 Lastbegrenzung Außentemperatur 2', $I, 'BLM.TempM31_30', true);
        $add('StratUBA', 'UBA_Folgeumkehr', 'Strategie UBA Folgeumkehr', $I, 'BLM.Folgeumkehr', true);
        $add('StratUBA', 'UBA_Anhebung', 'Strategie UBA Anhebung Sollwert hydr. Weiche', $I, 'BLM.Anhebung', true);
        $add('StratFM458', 'S458_KesselExtern', 'Strategie FM458 freigegebene Kessel (ext. Kontakt)', $I, 'BLM.Anzahl1_8', true);
        $add('StratFM458', 'S458_Schwelle1', 'Strategie FM458 Schwelle minimale Kesselanzahl', $I, 'BLM.TempM31_30', true);
        $add('StratFM458', 'S458_Schwelle2', 'Strategie FM458 Schwelle alle Kessel', $I, 'BLM.TempM31_30', true);
        $add('StratFM458', 'S458_MaxKessel', 'Strategie FM458 max. Kessel bei warmer Witterung', $I, 'BLM.Anzahl0_8', true);
        $add('StratFM458', 'S458_FolgeA', 'Strategie FM458 Kesselfolge A', $I, 'BLM.Folge39', true);
        $add('StratFM458', 'S458_FolgeB', 'Strategie FM458 Kesselfolge B', $I, 'BLM.Folge39', true);
        $add('StratFM458', 'S458_FolgeC', 'Strategie FM458 Kesselfolge C', $I, 'BLM.Folge39', true);
        $add('StratFM458', 'S458_FolgeD', 'Strategie FM458 Kesselfolge D', $I, 'BLM.Folge39', true);
        $add('StratFM458', 'S458_UmschaltA', 'Strategie FM458 Umschaltschwelle Folge A', $I, 'BLM.TempM20_30', true);
        $add('StratFM458', 'S458_UmschaltB', 'Strategie FM458 Umschaltschwelle Folge B', $I, 'BLM.TempM30_30', true);
        $add('StratFM458', 'S458_UmschaltC', 'Strategie FM458 Umschaltschwelle Folge C', $I, 'BLM.TempM30_30', true);

        $add('Solar', 'Solar_Kollektor', 'Kollektortemperatur', $F, $T);
        $add('Solar', 'Solar_Speicher1Unten', 'Solarspeicher 1 unten', $F, $T);
        $add('Solar', 'Solar_Speicher2Unten', 'Solarspeicher 2 unten', $F, $T);
        $add('Solar', 'Solar_Vorlauf', 'Solar Vorlauf (WMZ)', $F, $T);
        $add('Solar', 'Solar_Ruecklauf', 'Solar Rücklauf (WMZ)', $F, $T);
        $add('Solar', 'Solar_Pumpe', 'Solarpumpe', $I, 'BLM.Prozent');
        $add('Solar', 'Solar_Status1', 'Speicher 1 Status', $I, 'BLM.SolarStatus');
        $add('Solar', 'Solar_Status2', 'Speicher 2 Status', $I, 'BLM.SolarStatus');
        $add('Solar', 'Solar_Volumenstrom', 'Volumenstrom', $I, 'BLM.LiterH');
        $add('Solar', 'Solar_Leistung', 'Solarleistung', $I, '~Watt');
        $add('Solar', 'Solar_Waermemenge1', 'Solarertrag Speicher 1', $F, 'BLM.kWh');
        $add('Solar', 'Solar_Waermemenge2', 'Solarertrag Speicher 2', $F, 'BLM.kWh');
        $add('Solar', 'Solar_Betriebsstunden1', 'Solar Betriebsstunden Speicher 1', $F, 'BLM.Stunden');
        $add('Solar', 'Solar_Betriebsstunden2', 'Solar Betriebsstunden Speicher 2', $F, 'BLM.Stunden');
        $add('Solar', 'Solar_Betriebsart', 'Solar Betriebsart', $I, 'BLM.SolarModus', true);
        $add('Solar', 'Solar_Verbraucher', 'Solar Verbraucher', $I, 'BLM.SolarVerbraucher', true);
        $add('Solar', 'Solar_MaxSp1', 'Solar Maximaltemperatur Speicher 1', $I, 'BLM.SolarMax1', true);
        $add('Solar', 'Solar_MinSp1', 'Solar Minimaltemperatur Speicher 1', $I, 'BLM.SolarMin1', true);
        $add('Solar', 'Solar_MaxSp2', 'Solar Maximaltemperatur Speicher 2', $I, 'BLM.SolarMax2', true);
        $add('Solar', 'Solar_Glykol', 'Solar Glykolanteil', $I, 'BLM.Glykol', true);
        $add('Solar', 'Solar_FehlerKollektorfuehler', 'Fehler Kollektorfühler', $B, '~Alert');
        $add('Solar', 'Solar_Speicher1Max', 'Speicher 1 auf Maximum', $B, '~Switch');
        $add('Solar', 'Solar_KollektorMax', 'Kollektor auf Maximum', $B, '~Switch');

        return $defs;
    }

    // ------------------------------------------------------------------ Profile

    private function CreateProfiles(): void
    {
        $num = function (string $name, int $type, float $min, float $max, float $step, string $suffix, int $digits = 0) {
            if (!IPS_VariableProfileExists($name)) {
                IPS_CreateVariableProfile($name, $type);
            }
            IPS_SetVariableProfileValues($name, $min, $max, $step);
            IPS_SetVariableProfileText($name, '', $suffix);
            IPS_SetVariableProfileDigits($name, $digits);
        };
        $assoc = function (string $name, array $items) {
            if (!IPS_VariableProfileExists($name)) {
                IPS_CreateVariableProfile($name, self::T_INT);
            }
            foreach ($items as $v => $text) {
                IPS_SetVariableProfileAssociation($name, (int) $v, $text, '', -1);
            }
        };
        $I = self::T_INT;
        $F = self::T_FLOAT;
        $num('BLM.TempTag', $F, 10, 30, 0.5, ' °C', 1);
        $num('BLM.TempNacht', $F, 2, 30, 0.5, ' °C', 1);
        $num('BLM.Schwelle', $I, 9, 31, 1, ' °C');
        $num('BLM.Prozent', $I, 0, 100, 1, ' %');
        $num('BLM.Stunden99', $I, 0, 99, 1, ' h');
        $num('BLM.Tage99', $I, 0, 99, 1, ' Tage');
        $num('BLM.Aussenhalt', $I, -20, 10, 1, ' °C');
        $num('BLM.Auslegung', $I, 30, 90, 1, ' °C');
        $num('BLM.WWSoll', $I, 30, 60, 1, ' °C');
        $num('BLM.DesinfTemp', $I, 65, 75, 1, ' °C');
        $num('BLM.Stunde23', $I, 0, 23, 1, ' Uhr');
        $num('BLM.Stunde24', $I, 0, 24, 1, ' Uhr');
        $num('BLM.Nachtabsenkung', $I, 0, 40, 1, ' K');
        $num('BLM.Abgasgrenze', $I, 45, 250, 5, ' °C');
        $num('BLM.Stunden', $F, 0, 10000000, 0.1, ' h', 1);
        $num('BLM.kWh', $F, 0, 10000000, 0.1, ' kWh', 1);
        $num('BLM.LiterH', $I, 0, 100000, 1, ' l/h');
        $num('BLM.SolarMax1', $I, 30, 90, 1, ' °C');
        $num('BLM.SolarMin1', $I, 30, 55, 1, ' °C');
        $num('BLM.SolarMax2', $I, 20, 90, 1, ' °C');
        $num('BLM.Lastbegr', $I, -31, 31, 1, ' °C');
        $num('BLM.kW', $I, 0, 9999, 1, ' kW');
        $num('BLM.TempM30_30', $I, -30, 30, 1, ' °C');
        $num('BLM.TempM31_30', $I, -31, 30, 1, ' °C');
        $num('BLM.TempM20_30', $I, -20, 30, 1, ' °C');
        $num('BLM.Anzahl1_3', $I, 1, 3, 1, '');
        $num('BLM.Anzahl1_8', $I, 1, 8, 1, '');
        $num('BLM.Anzahl0_8', $I, 0, 8, 1, '');
        $num('BLM.Folge39', $I, 0, 39, 1, '');
        $num('BLM.Anhebung', $I, 0, 30, 1, ' K');
        $assoc('BLM.Kesselfolge', [1 => '1-2-3', 2 => '2-1-3', 3 => '3-2-1', 4 => '1-3-2', 5 => '2-3-1', 6 => '3-1-2']);
        $assoc('BLM.Folgeumkehr', [0 => 'Automatisch', 1 => 'Folge']);
        $assoc('BLM.Heizsystem', [0 => 'Kein Heizsystem', 1 => 'Heizkörper', 2 => 'Konvektor', 3 => 'Fußboden', 4 => 'Fußpunkt', 5 => 'Konstant', 6 => 'Raumregler', 7 => 'EIB']);
        $assoc('BLM.Schaltprogramm', [0 => 'Eigen', 1 => 'Familie', 2 => 'Früh', 3 => 'Spät', 4 => 'Vormittag', 5 => 'Nachmittag', 6 => 'Mittag', 7 => 'Single', 8 => 'Senior', 9 => 'Leer']);

        $assoc('BLM.Betriebsart', [0 => 'Manuell Nacht', 1 => 'Manuell Tag', 2 => 'Automatik']);
        $assoc('BLM.Desinfektionstag', [0 => 'Montag', 1 => 'Dienstag', 2 => 'Mittwoch', 3 => 'Donnerstag', 4 => 'Freitag', 5 => 'Samstag', 6 => 'Sonntag', 7 => 'täglich']);
        $assoc('BLM.ZirkLaeufe', [0 => 'ständig aus', 1 => 'ständig an', 2 => '2x pro Stunde', 3 => '3x pro Stunde', 4 => '4x pro Stunde', 5 => '5x pro Stunde', 6 => '6x pro Stunde', 7 => '7x pro Stunde']);
        $assoc('BLM.FerienArt', [0 => 'Abschalten (Frostschutz)', 1 => 'Reduziert', 2 => 'Raumhalt', 3 => 'Außenhalt']);
        $assoc('BLM.Brennerstufe', [0 => 'Aus', 1 => 'Stufe 1', 2 => 'Stufe 2']);
        $assoc('BLM.SolarModus', [0 => 'Aus', 1 => 'Automatik', 2 => 'Ein (30 min)']);
        $assoc('BLM.SolarVerbraucher', [0 => 'Automatisch', 1 => 'nur Speicher 1', 2 => 'nur Speicher 2']);
        $assoc('BLM.SolarStatus', [0 => 'Gesperrt', 1 => 'Zu wenig solarer Ertrag', 2 => 'Low Flow', 3 => 'High Flow', 4 => 'Hand ein', 5 => 'Umschalt-Check']);
        $assoc('BLM.Glykol', [20 => 'kein Glykol', 30 => '30 %', 40 => '40 %', 50 => '50 %']);
    }
}
