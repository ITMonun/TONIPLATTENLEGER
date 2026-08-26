/**
 * Kontaktformular-Endpunkt fuer toniplattenleger.ch
 *
 * Laeuft als Vercel Function (Node.js). Nimmt das Formular per POST entgegen
 * und stellt die Anfrage ueber authentifiziertes SMTP bei Hoststar zu.
 *
 * Das SMTP-Passwort steht ausschliesslich in den Vercel-Umgebungsvariablen.
 * Es taucht weder im Frontend noch in Git auf.
 */

import nodemailer from 'nodemailer';

const CONFIG = {
  host: process.env.SMTP_HOST || 'lx69.hoststar.hosting',
  port: Number(process.env.SMTP_PORT || 465),
  user: process.env.SMTP_USER || 'info@toniplattenleger.ch',
  pass: process.env.SMTP_PASSWORD,
  to: process.env.MAIL_TO || 'info@toniplattenleger.ch',
  fromName: 'Website Toni Plattenleger',
  toName: 'Toni Plattenleger GmbH',
  allowedOrigins: [
    'https://toniplattenleger.ch',
    'https://www.toniplattenleger.ch',
  ],
  minSecondsOnPage: 3,
  maxFormAgeHours: 2,
  rateLimitPerHour: 5,
};

// Best-effort Rate-Limit. Serverless-Instanzen werden wiederverwendet, aber
// nicht garantiert geteilt — Honeypot und Zeitfalle sind die Hauptabwehr.
const recentSends = new Map();

export default async function handler(req, res) {
  // ---------------------------------------------------------------- CORS
  const origin = req.headers.origin || '';
  const host = (req.headers.host || '').replace(/:\d+$/, '');
  let originHost = '';
  try {
    originHost = origin ? new URL(origin).hostname : '';
  } catch {
    originHost = '';
  }
  const sameOrigin = originHost && originHost.toLowerCase() === host.toLowerCase();

  if (origin && !sameOrigin) {
    if (!CONFIG.allowedOrigins.includes(origin)) {
      return json(res, 403, false, 'Diese Herkunft ist nicht freigegeben.');
    }
    res.setHeader('Access-Control-Allow-Origin', origin);
    res.setHeader('Vary', 'Origin');
    res.setHeader('Access-Control-Allow-Methods', 'POST, OPTIONS');
    res.setHeader('Access-Control-Allow-Headers', 'Content-Type');
    res.setHeader('Access-Control-Max-Age', '86400');
  }

  if (req.method === 'OPTIONS') {
    res.status(204).end();
    return;
  }
  if (req.method !== 'POST') {
    return json(res, 405, false, 'Nur POST wird unterstuetzt.');
  }

  // ------------------------------------------------------------- Eingaben
  const data = typeof req.body === 'string' ? safeParse(req.body) : (req.body || {});

  const name = clean(data.name, 120);
  const email = clean(data.email, 180);
  const phone = clean(data.phone, 60);
  const betreff = clean(data.betreff, 80);
  const message = String(data.message ?? '').trim();
  const honey = String(data.botcheck ?? '').trim();
  const ts = String(data.ts ?? '');

  // ----------------------------------------------------------- Spamschutz
  // 1. Honeypot — unsichtbares Feld. Bot glauben lassen, es habe geklappt.
  if (honey !== '') {
    return json(res, 200, true, 'Vielen Dank!');
  }

  // 2. Zeitfalle — sofort abgeschickt oder uralt = kein echter Besucher.
  if (/^\d+$/.test(ts)) {
    const ageSeconds = Math.floor(Date.now() / 1000) - Math.floor(Number(ts) / 1000);
    if (ageSeconds < CONFIG.minSecondsOnPage || ageSeconds > CONFIG.maxFormAgeHours * 3600) {
      return json(res, 429, false, 'Bitte laden Sie die Seite neu und versuchen Sie es erneut.');
    }
  }

  // 3. Rate-Limit pro IP.
  const ip = String(req.headers['x-forwarded-for'] || '').split(',')[0].trim() || 'unbekannt';
  if (!withinRateLimit(ip)) {
    return json(res, 429, false, 'Zu viele Anfragen. Bitte versuchen Sie es spaeter erneut oder rufen Sie uns an.');
  }

  // ---------------------------------------------------------- Validierung
  const errors = [];
  if (!name) errors.push('Name fehlt.');
  if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email)) errors.push('E-Mail-Adresse ist ungueltig.');
  if (message.length < 5) errors.push('Nachricht fehlt.');
  if (message.length > 5000) errors.push('Nachricht ist zu lang.');
  if (errors.length) {
    return json(res, 422, false, errors.join(' '));
  }

  if (!CONFIG.pass) {
    console.error('[kontakt] SMTP_PASSWORD ist nicht gesetzt.');
    return json(res, 500, false, 'Der Server ist nicht konfiguriert. Bitte kontaktieren Sie uns telefonisch.');
  }

  // ------------------------------------------------------------ Versenden
  const body = [
    'Neue Anfrage ueber das Kontaktformular',
    '='.repeat(40),
    '',
    `Name:     ${name}`,
    `E-Mail:   ${email}`,
    `Telefon:  ${phone || '—'}`,
    `Betreff:  ${betreff || '—'}`,
    '',
    'Nachricht:',
    message,
    '',
    '-'.repeat(40),
    `Gesendet: ${new Date().toLocaleString('de-CH', { timeZone: 'Europe/Zurich' })}`,
    `IP:       ${ip}`,
  ].join('\n');

  try {
    const transporter = nodemailer.createTransport({
      host: CONFIG.host,
      port: CONFIG.port,
      secure: CONFIG.port === 465, // implizites TLS
      auth: { user: CONFIG.user, pass: CONFIG.pass },
    });

    await transporter.sendMail({
      // Absender MUSS die authentifizierte Mailbox sein, sonst scheitert SPF.
      from: { name: CONFIG.fromName, address: CONFIG.user },
      to: { name: CONFIG.toName, address: CONFIG.to },
      replyTo: { name, address: email },   // Antworten geht direkt an den Kunden
      subject: 'Neue Anfrage ueber toniplattenleger.ch' + (betreff ? ` — ${betreff}` : ''),
      text: body,
    });

    recordSend(ip);
    return json(res, 200, true, 'Vielen Dank! Wir melden uns innerhalb von 24 Stunden.');
  } catch (err) {
    console.error('[kontakt] SMTP fehlgeschlagen:', err?.message || err);
    return json(res, 502, false, 'Die Nachricht konnte nicht gesendet werden.');
  }
}

/* ============================== Helfer ============================== */

/** Trimmt, kappt die Laenge und entfernt CR/LF (Header-Injection). */
function clean(value, max) {
  return String(value ?? '').replace(/[\r\n\0]/g, ' ').trim().slice(0, max);
}

function safeParse(raw) {
  try {
    return JSON.parse(raw);
  } catch {
    return {};
  }
}

function withinRateLimit(ip) {
  const cutoff = Date.now() - 3600_000;
  const stamps = (recentSends.get(ip) || []).filter((t) => t > cutoff);
  return stamps.length < CONFIG.rateLimitPerHour;
}

function recordSend(ip) {
  const cutoff = Date.now() - 3600_000;
  const stamps = (recentSends.get(ip) || []).filter((t) => t > cutoff);
  stamps.push(Date.now());
  recentSends.set(ip, stamps);
}

function json(res, status, success, message) {
  res.status(status).setHeader('Content-Type', 'application/json; charset=utf-8');
  res.end(JSON.stringify({ success, message }));
}
