# CLAUDE.md — Batteriewächter (DG65 Toolkit)

Konventionen: `/Users/dietmar/Nextcloud/Claude/TOOLKIT.md` und `SUITE.md` (Formular-Optik, Store-Review-Checkliste, Stolpersteine). Konzept: `.docs/Batterie-Konzept.md`.

- Technischer Name eingefroren, sobald veröffentlicht: Klasse `Batteriewaechter`, Präfix `BWACH`, Repo `DG65/Batteriewaechter`. Anzeigename „DG65 Toolkit Batteriewächter“.
- Entscheidungslogik ausschließlich in `Batteriewaechter/BWACHLogik.php` (reine Funktionen, ohne IPS). `module.php` sammelt Rohdaten und zeigt an.
- Jede Regeländerung braucht eine Prüfung in `.tools/test-bwach.php` UND, wenn sie eine Entscheidungsgrenze betrifft, eine Mutation in `.tools/mutation-bwach.php`. Vor jedem Commit: beide Skripte, `check-standalone.php`, `php -l`.
- Branch-Strategie: nur `beta`; `main` erst nach Live-Bewährung (Dietmar entscheidet).
- Alle JSON-Dateien UTF-8 ohne BOM (Lehre aus BY_BatterieMonitor); der Prüfstand prüft das.
- Standardwerte nie aus Dietmars Anlage ableiten; Hersteller nur als „z. B.“.
- Nicht live gesehen (nur Dokumentation): Zigbee2MQTT-/HomeMatic-Batterien und Matter-Batteriespannung. Matter-Batteriestand, „Ersatz erforderlich“ und Ersatz-Beschreibung sind an einem IKEA-Kontakt (Knoten 14, 08.10.2026) gesehen. Nie als geprüft ausgeben, was nicht gesehen wurde.
- Keine Verträge zu anderen Modulen (Dietmar, 07.10.2026: keine Verbindung zu WarnHub/Dashboard/EMS). `BWACH_GetState` nur bei konkretem Bedarf.
