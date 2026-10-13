<?php

declare(strict_types=1);

/**
 * Prozedur 3964R (Buderus Dokument 6301 1376) als Zustandsautomat.
 *
 * Die Klasse hat keine Abhaengigkeit zu IP-Symcon, haelt ihren kompletten Zustand in
 * toArray()/fromArray() und kann daher zwischen zwei Aufrufen persistiert werden.
 *
 * Rueckgabe von feed() und tick():
 *   send     string  Bytes, die auf die Leitung gehen muessen
 *   rx       array   komplett und fehlerfrei empfangene Nutzdaten-Bloecke (Binaerstrings)
 *   done     bool    der zuletzt gesendete Block wurde quittiert (DLE)
 *   fail     bool    Senden endgueltig gescheitert
 *   conflict bool    Initialisierungskonflikt, Gegenstelle sendet zuerst (Sendung muss wiederholt werden)
 */
final class L3964
{
    public const STX = 0x02;
    public const ETX = 0x03;
    public const DLE = 0x10;
    public const NAK = 0x15;

    /** Quittungsverzugszeit. Spezifiziert sind 2 s, ueber TCP bewusst grosszuegiger. */
    public const QVZ = 3.0;
    /** Zeichenverzugszeit beim Empfang. Spezifiziert sind 220 ms, ueber TCP grosszuegiger. */
    public const CHAR_TIMEOUT = 2.0;
    public const MAX_STX_TRIES = 3;
    public const MAX_DATA_TRIES = 6;

    private string $mode = 'idle'; // idle | rx | rxbcc | txstx | txack
    private string $rx = '';
    private bool $dle = false;
    private int $bcc = 0;
    private float $last = 0.0;
    private string $tx = '';
    private int $stxTries = 0;
    private int $dataTries = 0;
    private float $deadline = 0.0;

    /** Symcon transportiert Binaerdaten als UTF-8-JSON: Bytes 0x80-0xFF als Latin-1-Zeichen kodieren (ohne mbstring). */
    public static function toJsonString(string $bin): string
    {
        return (string) preg_replace_callback('/[\x80-\xFF]/', fn ($m) => chr(0xC0 | (ord($m[0]) >> 6)) . chr(0x80 | (ord($m[0]) & 0x3F)), $bin);
    }

    public static function fromJsonString(string $s): string
    {
        return (string) preg_replace_callback('/[\xC2\xC3][\x80-\xBF]/', fn ($m) => chr(((ord($m[0][0]) & 0x03) << 6) | (ord($m[0][1]) & 0x3F)), $s);
    }

    /** Block fuer die Leitung bauen (ohne fuehrendes STX): DLE verdoppeln, DLE ETX BCC anhaengen. */
    public static function encodeBlock(string $payload): string
    {
        $body = str_replace("\x10", "\x10\x10", $payload) . "\x10\x03";
        $bcc = 0;
        $n = strlen($body);
        for ($i = 0; $i < $n; $i++) {
            $bcc ^= ord($body[$i]);
        }
        return $body . chr($bcc);
    }

    public static function fromArray(?array $a): self
    {
        $o = new self();
        if (!is_array($a)) {
            return $o;
        }
        $o->mode = (string) ($a['mode'] ?? 'idle');
        $o->rx = hex2bin((string) ($a['rx'] ?? '')) ?: '';
        $o->dle = (bool) ($a['dle'] ?? false);
        $o->bcc = (int) ($a['bcc'] ?? 0);
        $o->last = (float) ($a['last'] ?? 0);
        $o->tx = hex2bin((string) ($a['tx'] ?? '')) ?: '';
        $o->stxTries = (int) ($a['stxTries'] ?? 0);
        $o->dataTries = (int) ($a['dataTries'] ?? 0);
        $o->deadline = (float) ($a['deadline'] ?? 0);
        return $o;
    }

    public function toArray(): array
    {
        return [
            'mode'      => $this->mode,
            'rx'        => bin2hex($this->rx),
            'dle'       => $this->dle,
            'bcc'       => $this->bcc,
            'last'      => $this->last,
            'tx'        => bin2hex($this->tx),
            'stxTries'  => $this->stxTries,
            'dataTries' => $this->dataTries,
            'deadline'  => $this->deadline,
        ];
    }

    public function isIdle(): bool
    {
        return $this->mode === 'idle';
    }

    /** Sendung starten (nur im Ruhezustand). Liefert die zu sendenden Bytes (STX). */
    public function send(string $payload, float $now): string
    {
        $this->tx = $payload;
        $this->stxTries = 1;
        $this->dataTries = 1;
        $this->mode = 'txstx';
        $this->deadline = $now + self::QVZ;
        return chr(self::STX);
    }

    public function feed(string $data, float $now): array
    {
        $r = $this->result();
        $bytes = unpack('C*', $data);
        if (is_array($bytes)) {
            foreach ($bytes as $b) {
                $this->byte($b, $now, $r);
            }
        }
        return $r;
    }

    public function tick(float $now): array
    {
        $r = $this->result();
        switch ($this->mode) {
            case 'txstx':
                if ($now > $this->deadline) {
                    $this->retryStx($now, $r);
                }
                break;
            case 'txack':
                if ($now > $this->deadline) {
                    $this->retryData($now, $r);
                }
                break;
            case 'rx':
            case 'rxbcc':
                if ($now - $this->last > self::CHAR_TIMEOUT) {
                    $r['send'] .= chr(self::NAK);
                    $this->toIdle();
                }
                break;
        }
        return $r;
    }

    private function result(): array
    {
        return ['send' => '', 'rx' => [], 'done' => false, 'fail' => false, 'conflict' => false];
    }

    private function toIdle(): void
    {
        $this->mode = 'idle';
        $this->rx = '';
        $this->dle = false;
        $this->bcc = 0;
    }

    private function startRx(float $now): void
    {
        $this->mode = 'rx';
        $this->rx = '';
        $this->dle = false;
        $this->bcc = 0;
        $this->last = $now;
    }

    private function retryStx(float $now, array &$r): void
    {
        $this->stxTries++;
        if ($this->stxTries > self::MAX_STX_TRIES) {
            $r['fail'] = true;
            $this->toIdle();
            return;
        }
        $this->mode = 'txstx';
        $this->deadline = $now + self::QVZ;
        $r['send'] .= chr(self::STX);
    }

    private function retryData(float $now, array &$r): void
    {
        $this->dataTries++;
        if ($this->dataTries > self::MAX_DATA_TRIES) {
            $r['fail'] = true;
            $this->toIdle();
            return;
        }
        $this->stxTries = 1;
        $this->mode = 'txstx';
        $this->deadline = $now + self::QVZ;
        $r['send'] .= chr(self::STX);
    }

    private function byte(int $b, float $now, array &$r): void
    {
        switch ($this->mode) {
            case 'idle':
                if ($b === self::STX) {
                    $r['send'] .= chr(self::DLE);
                    $this->startRx($now);
                }
                break;

            case 'rx':
                $this->last = $now;
                if ($this->dle) {
                    $this->dle = false;
                    if ($b === self::DLE) {
                        // doppeltes DLE = ein Datenbyte; beide DLE gehen in die Pruefsumme, XOR hebt sich auf
                        $this->rx .= chr(self::DLE);
                    } elseif ($b === self::ETX) {
                        $this->bcc ^= self::DLE ^ self::ETX;
                        $this->mode = 'rxbcc';
                    } else {
                        $r['send'] .= chr(self::NAK);
                        $this->toIdle();
                    }
                } elseif ($b === self::DLE) {
                    $this->dle = true;
                } else {
                    $this->rx .= chr($b);
                    $this->bcc ^= $b;
                }
                break;

            case 'rxbcc':
                if ($b === $this->bcc) {
                    $r['send'] .= chr(self::DLE);
                    $r['rx'][] = $this->rx;
                } else {
                    $r['send'] .= chr(self::NAK);
                }
                $this->toIdle();
                break;

            case 'txstx':
                if ($b === self::DLE) {
                    $r['send'] .= self::encodeBlock($this->tx);
                    $this->mode = 'txack';
                    $this->deadline = $now + self::QVZ;
                } elseif ($b === self::STX) {
                    // Initialisierungskonflikt: wir haben die niedrigere Prioritaet
                    $r['send'] .= chr(self::DLE);
                    $r['conflict'] = true;
                    $this->startRx($now);
                } else {
                    $this->retryStx($now, $r);
                }
                break;

            case 'txack':
                if ($b === self::DLE) {
                    $r['done'] = true;
                    $this->toIdle();
                } else {
                    $this->retryData($now, $r);
                }
                break;
        }
    }
}
