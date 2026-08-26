<?php
/**
 * Vorlage fuer die Server-Konfiguration.
 *
 * SO WIRD SIE BENUTZT:
 *   1. Diese Datei auf dem Hoststar-Server nach  api/config.php  kopieren.
 *   2. Dort das Passwort eintragen.
 *   3. config.php NIEMALS committen — sie steht in .gitignore.
 *
 * Das Passwort steht nur auf dem Server und nie im Frontend oder auf GitHub.
 */

return [
    // --- SMTP (Hoststar) ---
    'smtp_host' => 'lx69.hoststar.hosting',
    'smtp_port' => 465,
    'smtp_secure' => 'ssl',           // implizites TLS auf Port 465
    'smtp_user' => 'info@toniplattenleger.ch',
    'smtp_pass' => 'HIER-DAS-MAILBOX-PASSWORT-EINTRAGEN',

    // --- Adressen ---
    // Absender MUSS die authentifizierte Mailbox sein, sonst scheitert SPF/DMARC.
    // Die Adresse des Besuchers wird als Reply-To gesetzt.
    'mail_from' => 'info@toniplattenleger.ch',
    'from_name' => 'Website Toni Plattenleger',
    'mail_to'   => 'info@toniplattenleger.ch',
    'to_name'   => 'Toni Plattenleger GmbH',

    // --- Welche Websites duerfen das Formular abschicken? ---
    // Nur diese Origins erhalten CORS-Freigabe. Kein '*'.
    'allowed_origins' => [
        'https://toniplattenleger.ch',
        'https://www.toniplattenleger.ch',
        'https://itmonun.github.io',
    ],

    // --- Spamschutz ---
    'min_seconds_on_page' => 3,       // schneller = Bot
    'max_form_age_hours'  => 2,       // aelter = abgestandenes/wiederholtes Formular
    'rate_limit_per_hour' => 5,       // Absendungen pro IP und Stunde

    // --- Debug ---
    // true fuegt der JSON-Antwort technische Fehlerdetails hinzu. Auf einer
    // Live-Seite immer false lassen.
    'debug' => false,
];
