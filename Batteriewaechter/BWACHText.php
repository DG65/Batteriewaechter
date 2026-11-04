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
