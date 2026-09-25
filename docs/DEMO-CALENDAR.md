# Optionaler Beispielkalender

Im WordPress-Backend **Calendar Booking > Beispielkalender** oeffnen. Auch das Plugin-Dashboard verlinkt diese Seite. Die Option ist freiwillig: Installation, Aktivierung, Updates und Migrationen erzeugen niemals automatisch Beispiele.

Nach ausdruecklicher Bestaetigung legt **10 Beispieleintraege erzeugen** genau zehn Eintraege an. Ihre Daten liegen relativ zum Erzeugungsdatum, in der bei Erzeugung eingestellten Plugin-Zeitzone. Die private Vorschau zeigt alle betroffenen Monate mit sieben Wochentagen sowie eine vollstaendige Liste. Unterschiedliche Dauern, Wochenenden und ein mehrtaegiger Eintrag sind enthalten. Bestehende Beispiele behalten ihren Zeitraum; zum Aktualisieren erst entfernen, dann neu erzeugen.

**Beispieleintraege entfernen** entfernt nur den markierten Demo-Datensatz. Erneute Erstellung ist danach moeglich. Mehrfachklicks und parallele Anfragen werden durch eine eigene, begrenzt wartende MySQL-Sperre serialisiert. Es wird ein vollstaendiger Datensatz in einer einzelnen, nicht automatisch geladenen WordPress-Option gespeichert. Fehler werden nicht als Erfolg angezeigt; ein unvollstaendiger Datensatz wird nicht stillschweigend ueberschrieben.

## Abgrenzung zu echten Buchungen

Die Vorschau ist nur fuer Administratoren sichtbar und wird nicht als oeffentliche Seite veroeffentlicht. Die Daten liegen in `wpcb_demo_calendar`, nicht in Buchungs-, Ressourcen-, Zahlungs- oder Kalenderverbindungstabellen. Jeder Eintrag sowie der gesamte Datensatz sind technisch als Demo markiert. Es werden keine Kundeninformationen kopiert.

Erzeugen, Anzeigen und Entfernen stoessen keine E-Mails, Erinnerungen, DOI-Links, Zahlungen, Erstattungen, Webhooks, Kalender-Synchronisation oder Video-Meetings an. Es gibt keine produktiven Kapazitaetsblockaden, Buchungsberichte oder Kundendatenexporte fuer die Demo. Alle normalen Buchungen funktionieren unveraendert auch ohne Demo. Die Vorschau ist kein Nachweis fuer echte Mailzustellung, Zahlungsverarbeitung oder Anbieteranbindung.

Aenderungen erfordern Administratorrechte, POST, eine gueltige WordPress-Nonce und ausdrueckliche Bestaetigung. Die private Vorschau bleibt lesend. Die eigene Option wird durch die vorhandene Deinstallationsrichtlinie behandelt: bei Standard-Deinstallation bleiben Daten erhalten, bei ausdruecklicher vollstaendiger Datenloeschung wird sie zusammen mit den eigenen Plugin-Optionen entfernt.

## Abnahme

Lokale Tests pruefen die echte Demo-Klasse mit expliziten Options-/Sperrdoubles, auch an Zeitumstellungen, Monats-/Jahres- und Schaltjahrgrenzen. WordPress/MySQL-Tests pruefen reale Speicherung, einen nachgewiesenen Datenbank-Schreibfehler und zwei getrennte Prozesse. Ein Browser-Test am Installations-ZIP prueft Administratorbedienung, 0 -> 10 -> weiterhin 10 -> 0 -> 10, verweigerte Anfragen, Wochenenden und mehrtaegige Darstellung. Vorhandene echte Tabelleninhalte und Konfigurationen werden dabei auf unveraenderten Inhalt geprueft.

Diese Funktion ist der zusaetzliche Auftrag #181. Die separate Vorschau behebt nicht automatisch die Darstellungsfragen des produktiven Kalenders (#169) und ersetzt nicht die Release-Abnahme (#176/#178).
