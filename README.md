# AutoCode Invoices (self-hosted)

Private, single-user web app for an auto repair shop: clients, vehicles, jobs with
**Estimate → Repair Authorization → Final Invoice** flow, in-person touch signatures,
and downloadable PDFs. Interface 100% in English. No frameworks, no Composer —
plain PHP 8 + MySQL, built for Hostinger shared hosting.

> **In production:** this is the actual invoicing system used daily by
> [AutoCode](https://autocodelv.com), a mobile advanced automotive electronics
> service in Las Vegas, NV (module programming & cloning, no-start diagnostics,
> CAN-bus and electrical repair) — live at invoices.autocodelv.com (login required).
> It was designed, built and iterated in production by the shop's owner-technician.
>
> **Next step — AutoCode Copilot (Meta Global AI Developer Hackathon 2026):**
> an AI layer on top of this app. A photo of a scan-tool report + a short voice
> note in Spanish becomes a structured job, an estimate in plain English for the
> customer, and a signable invoice — using Muse Spark, Muse Image and SAM 3.1
> via the Meta Model API, with the technician approving every AI output first.
> This repository is the production foundation that Copilot will build on.

## Requirements

- PHP 8.0+ with extensions: `pdo_mysql`, `mbstring`, `gd` (gd only needed if you
  later manipulate images; FPDF itself needs none)
- MySQL 5.7+ / MariaDB 10.2+
- Apache with `.htaccess` support (or equivalent)

## Install

1. **Create the database** in Hostinger → Databases → MySQL (note the DB name, user, password).
2. **Upload** the contents of this folder to the subdomain/document root
   (e.g. `invoices.autocodelv.com`). Do **not** link it from the public site menu.
3. **Configure:** copy `config.sample.php` → `config.php` and fill in:
   - `DB_HOST`, `DB_NAME`, `DB_USER`, `DB_PASS`
   - `EXPORT_TOKEN` — generate with `openssl rand -hex 32` (64 chars)
   - `APP_URL` — the app's URL, no trailing slash
4. **Run the installer:** open `https://YOUR-DOMAIN/install.php`, create the admin
   user (min. 8-char password). It creates all tables and seeds the business info.
5. **Delete `install.php`** from the server (it also locks itself via `install.lock`).
6. Log in at `https://YOUR-DOMAIN/login.php`.

> `schema.sql` is the canonical schema if you prefer to import it manually
> (`mysql -u USER -p DBNAME < schema.sql`) and skip step 4's table creation.

## Automatic backup to Google Drive

`export.php?token=YOUR_EXPORT_TOKEN` returns a full JSON dump of the database
(signature images are referenced by their stored relative paths). Point a scheduled
job at it, e.g. a nightly cron on the server or an external script:

```sh
curl -s "https://invoices.autocodelv.com/export.php?token=YOUR_EXPORT_TOKEN" \
  -o "/backups/invoices-$(date +%F).json"
```

then upload that file to Google Drive.

## How it works

- **Jobs** (`index.php`): global search (client, phone, plate, VIN, problem, job #)
  + status filter. `+ New Job` wizard: find/create client → pick/add vehicle →
  describe the problem → job starts as **Estimate**.
- **Job page** (`job.php?id=`): one living document with tabs.
  - *Estimate* — add/delete lines, finalize (locks), convert to Authorization.
  - *Authorization* — estimate lines carry over; add more lines; customer signs
    on the touch canvas → finalized snapshot + PDF.
  - *Invoice* — add/edit/delete lines and prices; Warranty & Policies shown;
    customer signs to accept → finalized snapshot + PDF. Then "Mark as Paid".
  - Finalizing a stage saves an **immutable JSON snapshot**; the PDF is generated
    from that snapshot, so it never changes afterwards.
- **Client page** (`client.php?id=`): info, vehicles, and the **full job history** —
  every visit with date, vehicle, problem, status, total, and PDF links for each
  finalized document.
- **Settings**: business name/address/phone (printed on all PDFs), tax rate,
  editable Warranty & Policies, password change.

## Security notes

- PHP sessions, `password_hash()` passwords, PDO prepared statements everywhere.
- CSRF token on every POST form.
- `robots.txt` disallows all crawlers + `noindex, nofollow` meta on every page.
- `uploads/signatures/` is blocked from direct HTTP; images are served through
  `file.php` (login required). `config.php` is blocked via `.htaccess`.
- The app is private by design: keep the URL unlinked and never share the
  export token.

## Project structure

```
config.sample.php      → copy to config.php (never commit the real one)
schema.sql             → canonical MySQL schema
install.php            → one-time installer (DELETE after use)
login.php / logout.php
index.php              → jobs dashboard
clients.php / client.php
new_job.php            → 3-step wizard
job.php                → tabbed living document + signatures
settings.php
pdf.php                → finalized-document PDF download
file.php               → authenticated signature image proxy
export.php         → token-protected full DB dump (backups)
includes/              → functions.php (bootstrap), layout.php
lib/                   → pdfgen.php + fpdf/ (FPDF 1.86, bundled)
assets/                → css/style.css, js/app.js (no dependencies)
uploads/signatures/    → signature PNGs (HTTP-blocked)
robots.txt, .htaccess
```
