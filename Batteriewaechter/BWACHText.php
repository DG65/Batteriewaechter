<?php

declare(strict_types=1);

// ===========================================================================
// Übersetzung für alle Teile des Batteriewächters. Quelltexte stehen auf
// Englisch im Code, die deutsche Fassung in locale.json (Symcons übliches
// Muster; die Datei wird aus .tools/locale-teile/*.json erzeugt:
// php .tools/locale-bauen.php).
//
// Die reinen Logik-Klassen haben kein $this->Translate(); das Modul setzt
// deshalb im Konstruktor seinen Übersetzer. Ohne Übersetzer bleibt der
// englische Text stehen.
// ===========================================================================

final class BWACHText
{
    /** @var callable|null fn(string): string */
    private static $uebersetzer = null;

    public static function setzen(?callable $uebersetzer): void
    {
        self::$uebersetzer = $uebersetzer;
    }

    /** @var array<string,string> gemerkte Übersetzungen */
    private static $gemerkt = [];
    /** @var int Zähler für den Prüfstand: wie oft wurde Symcon wirklich gefragt */
    public static $anfragen = 0;

    /**
     * Übersetzer für das Modul (SUITE.md Stolperstein 24): Symcons Translate() liest bei jedem Aufruf locale.json.
     * Eine Prüfung übersetzt hunderte Texte und dauerte live 2,5 s. Deshalb wird jeder Text höchstens einmal je
     * Prozess bei Symcon erfragt und gemerkt. Keine eigene Datei wird gelesen (Store: Dateizugriff nur als Ausnahme).
     */
    public static function mitMerkliste(callable $translate): callable
    {
        return function (string $text) use ($translate): string {
            if (!isset(self::$gemerkt[$text])) {
                self::$anfragen++;
                try {
                    self::$gemerkt[$text] = (string)$translate($text);
                } catch (\Throwable $e) {
                    // z. B. Instanz während des Aufrufs gelöscht: Quelltext zeigen, nichts merken.
                    return $text;
                }
            }
            return self::$gemerkt[$text];
        };
    }

    /** Nur für den Prüfstand: Merkliste leeren. */
    public static function vergessen(): void
    {
        self::$gemerkt = [];
        self::$anfragen = 0;
    }

    /** Text übersetzen; mit Argumenten wie sprintf (Platzhalter %s/%d, ein Prozentzeichen als %%). */
    public static function t(string $text, ...$args): string
    {
        $uebersetzt = self::$uebersetzer !== null ? (string)(self::$uebersetzer)($text) : $text;
        if ($args === []) {
            return $uebersetzt;
        }
        $aus = @vsprintf($uebersetzt, array_map(function ($a) {
            return is_scalar($a) || $a === null ? (string)$a : (string)json_encode($a);
        }, $args));
        return $aus === false ? $uebersetzt : $aus;
    }
}
