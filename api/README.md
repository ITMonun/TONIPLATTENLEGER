# Kontaktformular — Vercel Function

Das Formular auf `kontakt.html` sendet an `/api/send`. Die Function stellt die
Anfrage per authentifiziertem SMTP bei Hoststar an `info@toniplattenleger.ch` zu.

## Einrichtung

Es muss genau **eine** Umgebungsvariable gesetzt werden — das Passwort der
Mailbox. Alles andere hat sinnvolle Voreinstellungen.

Im Vercel-Dashboard: **Settings → Environment Variables**

| Name | Wert | Umgebungen |
|---|---|---|
| `SMTP_PASSWORD` | Passwort von `info@toniplattenleger.ch` | Production, Preview, Development |

Oder per CLI:

```bash
vercel env add SMTP_PASSWORD production
```

Danach **neu deployen** — Umgebungsvariablen greifen erst im naechsten Build.

### Optionale Variablen

Nur setzen, wenn vom Standard abgewichen werden soll:

| Name | Standard |
|---|---|
| `SMTP_HOST` | `lx69.hoststar.hosting` |
| `SMTP_PORT` | `465` |
| `SMTP_USER` | `info@toniplattenleger.ch` |
| `MAIL_TO` | `info@toniplattenleger.ch` |

## Wichtig

* Das Passwort steht **nur** in den Vercel-Umgebungsvariablen — nie im Code,
  nie in Git, nie im Frontend.
* Absender ist immer `info@toniplattenleger.ch` (die authentifizierte Mailbox),
  sonst scheitern SPF/DMARC. Die Adresse des Besuchers steht im `Reply-To`,
  ein Klick auf "Antworten" geht also direkt an den Kunden.

## Spamschutz

| Mechanismus | Wirkung |
|---|---|
| Honeypot `botcheck` | unsichtbares Feld; ausgefuellt = Bot, wird still verworfen |
| Zeitfalle `ts` | Absenden unter 3 Sekunden oder nach ueber 2 Stunden wird abgelehnt |
| Rate-Limit | max. 5 pro IP und Stunde (best effort — Instanzen werden geteilt, aber nicht garantiert) |
| Origin-Pruefung | gleiche Herkunft immer erlaubt, fremde nur aus `allowedOrigins` |
| Laengenlimits | Name 120, E-Mail 180, Nachricht 5000 Zeichen |
| CR/LF-Filter | verhindert Header-Injection |

## Fehlersuche

Kommt keine Mail an: **Vercel Dashboard → Deployments → Functions → Logs**.
Die Function protokolliert `[kontakt] SMTP fehlgeschlagen: …` mit dem Grund.
Zugangsdaten erscheinen dabei nie.
