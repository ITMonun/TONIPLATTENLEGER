# Kontaktformular — Backend

Das Formular auf `kontakt.html` schickt seine Daten an `api/send.php`.
Das Skript stellt die Anfrage per authentifiziertem SMTP an
`info@toniplattenleger.ch` zu.

## Einrichtung auf dem Hoststar-Server

1. **Dateien hochladen** — den gesamten Ordner `api/` in das Web-Root laden
   (per FTP/SFTP oder im Hoststar-Dateimanager).

2. **Konfiguration anlegen.** `api/config.example.php` kopieren und das
   Passwort der Mailbox eintragen. Das Skript sucht die Datei in dieser
   Reihenfolge:

   | Ort | Empfehlung |
   |---|---|
   | `../../tpl-mail-config.php` (ueber dem Web-Root) | **bevorzugt** — per HTTP nicht erreichbar |
   | `../tpl-mail-config.php` | gut |
   | `api/config.php` | funktioniert, aber im Web-Root |

   Hoststar liefert ueber nginx aus, dort greift `.htaccess` nicht. Deshalb
   die Datei moeglichst ueber das Web-Root legen.

3. **Testen** — Formular auf der Website abschicken. Kommt keine Mail an,
   in `api/config.php` kurzzeitig `'debug' => true` setzen; die JSON-Antwort
   nennt dann den fehlgeschlagenen SMTP-Schritt. Danach wieder auf `false`.
   Zugangsdaten erscheinen dabei nie in der Ausgabe.

## Wichtig

* `config.php` steht in `.gitignore` und darf **nie** committet werden.
* Absender ist immer `info@toniplattenleger.ch` (die authentifizierte
  Mailbox), sonst scheitern SPF/DMARC. Die Adresse des Besuchers steht im
  `Reply-To`, ein Klick auf "Antworten" geht also direkt an den Kunden.

## Spamschutz

| Mechanismus | Wirkung |
|---|---|
| Honeypot `botcheck` | unsichtbares Feld; ausgefuellt = Bot, wird still verworfen |
| Zeitfalle `ts` | Absenden unter 3 Sekunden oder nach ueber 2 Stunden wird abgelehnt |
| Rate-Limit | max. 5 Absendungen pro IP und Stunde |
| Origin-Pruefung | nur die Domains aus `allowed_origins` duerfen senden |
| Laengenlimits | Name 120, E-Mail 180, Nachricht 5000 Zeichen |
| CR/LF-Filter | verhindert Header-Injection |

## Wenn die Website auf GitHub Pages bleibt

Dann laufen Formular und Endpunkt auf verschiedenen Domains. `allowed_origins`
in der Konfiguration muss die Pages-Domain enthalten (ist voreingestellt).
Liegt die Website dagegen selbst auf Hoststar, ist alles gleiche Herkunft und
die Liste wird gar nicht gebraucht.
