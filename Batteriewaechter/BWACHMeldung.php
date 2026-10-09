<?php

// ===========================================================================
// BWACHMeldung — reine Meldungslogik des Batteriewächters (DG65-Toolkit).
//
// Ohne IPS-Aufrufe, wie BWACHLogik: Zustandsautomat „Meldungen ohne Nerven“,
// Ruhezeiten, Wochenbericht, Wechselerkennung, Tagebuch, Textbausteine.
//
// Grundsätze:
//   - Nicht bei jeder Prüfung wiederholen: erste Meldung, dann Erinnerung nach
//     N Tagen (kritische Geräte früher), kein Dauerfeuer.
//   - Mehrere Befunde eines Laufs werden zu EINER Nachricht gebündelt.
//   - Ruhezeiten verschieben, sie verwerfen nichts (kritische Geräte können
//     die Ruhezeit durchbrechen).
//   - Wackelnde Werte (leer → ok → leer) melden nicht jedes Mal neu: ein
//     Befund gilt erst nach einer Karenzzeit als beendet.
//   - Quittieren („getauscht“, „zurückgestellt“) unterdrückt, löscht aber nie
//     still: nach Ablauf meldet sich der Wächter wieder, wenn der Befund bleibt.
// ===========================================================================

final class BWACHMeldung
{
    public const PROB_EMPTY  = 'leer';
    public const PROB_LOW    = 'schwach';
    public const PROB_SILENT = 'still';
    public const PROB_SOON   = 'bald';     // laut Prognose bald leer (nur bei ausreichender Sicherheit)
    public const PROB_PREVENT = 'vorsorge'; // vorsorglicher Wechsel nach festem Intervall fällig (nur kritische Geräte)

    public const ACK_REPLACED = 'getauscht';
    public const ACK_SNOOZE   = 'zurueckgestellt';
    public const ACK_RETIRED  = 'ausser_betrieb';

    /** Nach „getauscht“ gibt der Wächter dem Gerät so lange Zeit, den neuen Stand zu melden. */
    public const REPLACE_GRACE_DAYS = 3;

    /** Batteriewechsel, die im Tagebuch näher beieinander liegen als so viele Tage, gelten als EIN Wechsel. */
    public const DIARY_DEDUPE_DAYS = 3;

    public const DIARY_CAP = 500;

    /** Befunde eines Geräts, die eine Meldung auslösen (Datenqualität kommt nur in den Wochenbericht). */
    public static function problems(array $r): array
    {
        $p = [];
        if (($r['status'] ?? '') === BWACHLogik::ST_EMPTY) {
            $p[] = self::PROB_EMPTY;
        } elseif (($r['status'] ?? '') === BWACHLogik::ST_LOW) {
            $p[] = self::PROB_LOW;
        }
        if (($r['funk'] ?? '') === 'still') {
            $p[] = self::PROB_SILENT;
        }
        if (!empty($r['soon'])) {
            $p[] = self::PROB_SOON;
        }
        if (!empty($r['preventive'])) {
            $p[] = self::PROB_PREVENT;
        }
        return $p;
    }

    /** Ruhezeit: von-Stunde (einschließlich) bis bis-Stunde (ausschließlich), auch über Mitternacht. */
    public static function isQuiet(int $now, bool $enabled, int $fromHour, int $toHour): bool
    {
        if (!$enabled || $fromHour === $toHour) {
            return false;
        }
        $h = (int)date('G', $now);
        return $fromHour < $toHour ? ($h >= $fromHour && $h < $toHour) : ($h >= $fromHour || $h < $toHour);
    }

    private static function newState(int $now): array
    {
        return ['probs' => [], 'since' => $now, 'notified' => 0, 'cnt' => 0, 'esc' => false, 'gone' => 0, 'snooze' => 0];
    }

    /**
     * Entscheidet, was gemeldet wird.
     *
     * @param array $state   bisheriger Zustand je Geräteschlüssel
     * @param array $current Geräteschlüssel => ['probs'=>string[], 'critical'=>bool]  (nur Geräte MIT Befund)
     * @param array $p       ['remDays','critRemDays','escHours','clearHours','quiet','ignoreQuiet']
     * @return array ['state'=>…, 'events'=>['neu'=>[keys], 'erinnerung'=>[keys], 'eskalation'=>[keys]]]
     */
    public static function decide(array $state, array $current, int $now, array $p): array
    {
        $events     = ['neu' => [], 'erinnerung' => [], 'eskalation' => []];
        $remDays    = max(1, (int)($p['remDays'] ?? 7));
        $critRem    = max(1, (int)($p['critRemDays'] ?? 2));
        $escHours   = (int)($p['escHours'] ?? 24);
        $clearHours = (int)($p['clearHours'] ?? 24);
        $quietNow   = (bool)($p['quiet'] ?? false);
        $ignoreQuiet = (bool)($p['ignoreQuiet'] ?? true);

        foreach ($current as $key => $c) {
            $key  = (string)$key;
            $s    = $state[$key] ?? self::newState($now);
            $probs = array_values($c['probs']);
            sort($probs);
            $isNew = count(array_diff($probs, $s['probs'])) > 0;
            $s['gone'] = 0;
            if ($isNew) {
                // Ein neuer Befund (z. B. von „schwach“ auf „leer“) ist eine neue Meldung.
                $s['notified'] = 0;
                $s['esc']      = false;
                $s['since']    = $now;
            }
            $s['probs'] = $probs;

            $quiet = $quietNow && !($ignoreQuiet && !empty($c['critical']));
            if ($now >= (int)$s['snooze'] && !$quiet) {
                if ($s['notified'] === 0) {
                    $events['neu'][] = $key;
                    $s['notified'] = $now;
                    $s['cnt'] = 1;
                } else {
                    $interval = (!empty($c['critical']) ? $critRem : $remDays) * 86400;
                    if ($now - $s['notified'] >= $interval) {
                        $events['erinnerung'][] = $key;
                        $s['notified'] = $now;
                        $s['cnt']++;
                    }
                }
                if (!empty($c['critical']) && $escHours > 0 && !$s['esc'] && $s['notified'] > 0
                    && $now - (int)$s['since'] >= $escHours * 3600) {
                    $events['eskalation'][] = $key;
                    $s['esc'] = true;
                }
            }
            $state[$key] = $s;
        }

        // Befund verschwunden: erst nach der Karenzzeit vergessen (wackelnde Werte)
        foreach ($state as $key => $s) {
            if (isset($current[$key]) || isset($current[(int)$key])) {
                continue;
            }
            if ($s['gone'] === 0) {
                $state[$key]['gone'] = $now;
            } elseif ($now - $s['gone'] >= $clearHours * 3600 && $now >= (int)$s['snooze']) {
                unset($state[$key]);
            }
        }
        return ['state' => $state, 'events' => $events];
    }

    /** Quittieren. Gibt den neuen Zustand zurück; „ausser_betrieb“ wird vom Modul separat geführt. */
    public static function acknowledge(array $state, string $key, string $action, int $now, int $snoozeDays): array
    {
        $s = $state[$key] ?? self::newState($now);
        if ($action === self::ACK_REPLACED) {
            $until = $now + self::REPLACE_GRACE_DAYS * 86400;
            $s['snooze'] = $until; $s['since'] = $until; $s['notified'] = 0; $s['esc'] = false; $s['probs'] = [];
        } elseif ($action === self::ACK_SNOOZE) {
            $until = $now + max(1, $snoozeDays) * 86400;
            $s['snooze'] = $until; $s['since'] = $until; $s['esc'] = false;
            if ($s['notified'] === 0) {
                $s['notified'] = $now;
            }
        } elseif ($action === self::ACK_RETIRED) {
            unset($state[$key]);
            return $state;
        }
        $state[$key] = $s;
        return $state;
    }

    // =====================================================================
    //  Wechselerkennung und Tagebuch
    // =====================================================================

    /**
     * @param array|null $prev ['p'=>float|null,'f'=>bool|null]  zuletzt gesehen (f = true: „schwach“)
     * @param array      $cur  dasselbe, aktuell
     * @return string|null Beschreibung des Sprungs, null = kein Wechsel
     */
    public static function detectReplacement(?array $prev, array $cur, int $jumpPct): ?string
    {
        if ($prev === null) {
            return null;
        }
        if ($prev['p'] !== null && $cur['p'] !== null && $cur['p'] - $prev['p'] >= $jumpPct) {
            return 'Prozentwert sprang von ' . BWACHLogik::num((float)$prev['p']) . ' % auf ' . BWACHLogik::num((float)$cur['p']) . ' %';
        }
        if ($prev['f'] === true && $cur['f'] === false) {
            return '„Batterie schwach“-Flag wurde zurückgesetzt';
        }
        return null;
    }

    /** Fügt dem Tagebuch einen Eintrag hinzu; Doppelte innerhalb weniger Tage werden zusammengefasst. */
    public static function diaryAdd(array $diary, array $entry): array
    {
        foreach ($diary as $e) {
            if ($e['key'] === $entry['key'] && abs($entry['t'] - $e['t']) < self::DIARY_DEDUPE_DAYS * 86400) {
                return $diary;
            }
        }
        $diary[] = $entry;
        if (count($diary) > self::DIARY_CAP) {
            $diary = array_slice($diary, -self::DIARY_CAP);
        }
        return $diary;
    }

    /**
     * Liest ein Datum „TT.MM.JJJJ“ oder „JJJJ-MM-TT“ als Zeitstempel (12:00 Uhr). Ungültige Tage (31.02.),
     * Daten in der Zukunft und Jahre vor 2015 ergeben null.
     */
    public static function parseDate(string $s, int $now): ?int
    {
        $s = trim($s);
        if (preg_match('/^(\d{1,2})\.(\d{1,2})\.(\d{4})$/', $s, $m)) {
            [$d, $mo, $y] = [(int)$m[1], (int)$m[2], (int)$m[3]];
        } elseif (preg_match('/^(\d{4})-(\d{1,2})-(\d{1,2})$/', $s, $m)) {
            [$y, $mo, $d] = [(int)$m[1], (int)$m[2], (int)$m[3]];
        } else {
            return null;
        }
        if ($y < 2015 || !checkdate($mo, $d, $y)) {
            return null;
        }
        $t = mktime(12, 0, 0, $mo, $d, $y);
        return $t > $now ? null : $t;
    }

    /** Zeitstempel des jüngsten Wechsels eines Geräts im Tagebuch, null wenn keiner erfasst ist. */
    public static function lastReplacement(array $diary, string $key): ?int
    {
        $last = null;
        foreach ($diary as $e) {
            if ((string)$e['key'] === $key && ($last === null || (int)$e['t'] > $last)) {
                $last = (int)$e['t'];
            }
        }
        return $last;
    }

    /**
     * Vorsorglicher Wechsel: nach festem Intervall, auch wenn die Batterie noch gut ist.
     *
     * @param int|null $last   jüngster erfasster Wechsel (Zeitstempel) oder null
     * @param int      $months Intervall in Monaten, 0 = aus
     * @return array ['due'=>bool,'months'=>float|null (Monate seit dem Wechsel)]
     */
    public static function preventiveDue(?int $last, int $months, int $now): array
    {
        if ($months <= 0 || $last === null) {
            return ['due' => false, 'months' => $last === null ? null : round(($now - $last) / (365.25 / 12 * 86400), 1)];
        }
        $since = ($now - $last) / (365.25 / 12 * 86400);
        return ['due' => $since >= $months, 'months' => round($since, 1)];
    }

    // =====================================================================
    //  Wochenbericht
    // =====================================================================

    /** Gibt den Schlüssel der fälligen Woche („2026-W41“) zurück, sonst null. */
    public static function digestDue(int $now, bool $enabled, int $weekday, int $hour, string $lastKey): ?string
    {
        if (!$enabled) {
            return null;
        }
        $key = date('o-\WW', $now);
        if ($key === $lastKey) {
            return null;
        }
        if ((int)date('N', $now) !== $weekday || (int)date('G', $now) < $hour) {
            return null;
        }
        return $key;
    }

    // =====================================================================
    //  Texte
    // =====================================================================

    /**
     * @param string $kind  'neu' | 'erinnerung' | 'eskalation'
     * @param array  $items Liste von ['name','place','probs'=>[],'text'=>string,'critical'=>bool]
     * @return array ['title','text','sound']
     */
    public static function message(string $kind, array $items): array
    {
        $n = count($items);
        $first = $items[0];
        $anyEmpty = false;
        $anyCritical = false;
        foreach ($items as $i) {
            $anyEmpty = $anyEmpty || in_array(self::PROB_EMPTY, $i['probs'], true);
            $anyCritical = $anyCritical || !empty($i['critical']);
        }

        if ($kind === 'eskalation') {
            // Titel bleiben unter 32 Byte (Push-Grenze): der Gerätename steht im Text
            $title = $n === 1 ? '❗ Unbeachtet' : '❗ ' . $n . ' Geräte unbeachtet';
        } elseif ($kind === 'erinnerung') {
            $title = $n === 1 ? '🔔 Erinnerung' : '🔔 Erinnerung: ' . $n . ' Geräte';
        } elseif ($n === 1) {
            $p = $first['probs'];
            if (in_array(self::PROB_EMPTY, $p, true)) {
                $title = '🪫 Batterie leer';
            } elseif (in_array(self::PROB_LOW, $p, true)) {
                $title = '⚠️ Batterie schwach';
            } elseif (in_array(self::PROB_SILENT, $p, true)) {
                $title = '🔇 Funkstille';
            } elseif (in_array(self::PROB_PREVENT, $p, true) && !in_array(self::PROB_SOON, $p, true)) {
                $title = '🗓 Vorsorglich tauschen';
            } else {
                $title = '⏳ Batterie bald leer';
            }
        } else {
            $title = '🔋 ' . $n . ' Batteriemeldungen';
        }

        $lines = [];
        foreach (array_slice($items, 0, 5) as $i) {
            $where = $i['place'] !== '' ? ' (' . $i['place'] . ')' : '';
            $lines[] = '• ' . $i['name'] . $where . ': ' . $i['text'] . (!empty($i['critical']) ? ' ❗' : '');
        }
        if ($n > 5) {
            $lines[] = '… und ' . ($n - 5) . ' weitere';
        }
        $sound = ($kind === 'eskalation' || ($anyEmpty && $anyCritical)) ? 'alarm' : 'bell';
        return ['title' => $title, 'text' => implode("\n", $lines), 'sound' => $sound];
    }

    /** Wochenbericht; $rows = Liste von ['name','place','text','urgency']. */
    public static function digest(array $sum, array $rows, array $extra = []): array
    {
        $head = $sum['total'] . ' Geräte überwacht: ' . $sum['empty'] . ' leer, ' . $sum['low'] . ' schwach, '
            . (($sum['soon'] ?? 0) > 0 ? $sum['soon'] . ' bald leer, ' : '')
            . $sum['silent'] . ' Funkstille, ' . $sum['check'] . ' mit zweifelhaften Daten.';
        // Kennzahlen und Zusatzzeilen (Einkauf) zuerst: Push kürzt bei 256 Byte, vorn steht das Wichtigste
        $lines = [$head];
        foreach ($extra as $l) {
            $lines[] = $l;
        }
        $lines[] = '';
        $shown = 0;
        foreach ($rows as $r) {
            if ($r['urgency'] <= 0) {
                continue;
            }
            if (++$shown > 15) {
                $lines[] = '… und weitere (alle in der Tabelle „Handlungsbedarf“)';
                break;
            }
            $where = $r['place'] !== '' ? ' (' . $r['place'] . ')' : '';
            $lines[] = '• ' . $r['name'] . $where . ': ' . $r['text'];
        }
        if ($shown === 0) {
            $lines[] = '✅ Alles in Ordnung.';
        }
        return ['title' => '🔋 Batterie-Wochenbericht', 'text' => implode("\n", $lines), 'sound' => 'bell', 'anyProblem' => $shown > 0];
    }

    /**
     * Macht aus der Meldung des SMTP-Moduls (PHP-Warnungen, z. B. „Login denied“) einen Satz, der sagt,
     * was zu tun ist. Unbekanntes wird unverändert (und als solches kenntlich) durchgereicht.
     *
     * @param string[] $warnings
     */
    public static function explainMailError(array $warnings, string $exception = ''): string
    {
        $raw = trim(implode(' / ', array_filter(array_map('trim', $warnings))) . ($exception !== '' ? ' / ' . $exception : ''));
        if ($raw === '') {
            return 'das SMTP-Modul nennt keinen Grund (Debug der SMTP-Instanz einschalten zeigt den Dialog mit dem Server)';
        }
        $h = mb_strtolower($raw);
        if (strpos($h, 'login denied') !== false || strpos($h, 'authentication') !== false || strpos($h, 'auth failed') !== false || strpos($h, '535') !== false) {
            $why = 'Anmeldung abgelehnt: Benutzername oder Passwort der SMTP-Instanz stimmen nicht (bei iCloud und anderen Anbietern mit Zwei-Faktor-Anmeldung braucht es ein App-spezifisches Passwort)';
        } elseif (strpos($h, 'resolve') !== false || strpos($h, 'getaddrinfo') !== false) {
            $why = 'Server nicht gefunden: Servername der SMTP-Instanz prüfen';
        } elseif (strpos($h, 'timed out') !== false || strpos($h, 'timeout') !== false) {
            $why = 'Zeitüberschreitung: der Mailserver antwortet nicht (Server, Port und Netzwerk prüfen)';
        } elseif (strpos($h, 'refused') !== false) {
            $why = 'Verbindung abgelehnt: Server oder Port der SMTP-Instanz prüfen';
        } elseif (strpos($h, 'certificate') !== false || strpos($h, 'ssl') !== false || strpos($h, 'tls') !== false) {
            $why = 'Verschlüsselung oder Zertifikat: SSL-Einstellung und Port der SMTP-Instanz prüfen';
        } elseif (strpos($h, 'recipient') !== false || strpos($h, 'rcpt') !== false) {
            $why = 'Empfänger abgelehnt: Empfängeradresse prüfen';
        } else {
            $why = 'Meldung des SMTP-Moduls';
        }
        return $why . ' („' . $raw . '“)';
    }

    /** Kürzt UTF-8-sicher auf eine Byte-Grenze (WFC_PushNotification/VISU_PostNotificationEx, SUITE.md Stolperstein 22). */
    public static function truncateBytes(string $str, int $maxBytes): string
    {
        if (strlen($str) <= $maxBytes) {
            return $str;
        }
        $result = '';
        $bytes  = 0;
        foreach (mb_str_split($str) as $char) {
            $cb = strlen($char);
            if ($bytes + $cb > $maxBytes) {
                break;
            }
            $result .= $char;
            $bytes  += $cb;
        }
        return $result;
    }
}
