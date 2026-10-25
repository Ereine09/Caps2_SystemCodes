# Deployment Guide — Loyalty Management System → Hostinger

This document explains exactly how to take this project from the local XAMPP
environment to a live Hostinger deployment, **without restructuring the
codebase**. See the audit report (delivered in chat) for why a physical
folder reorganization was deliberately NOT performed — in short: the app is
held together by dozens of `__DIR__ . '/../../...'`-style relative
`require`/`include` paths, and Hostinger has no requirement on folder naming
or layout. Moving files is a large-blast-radius change for zero deployability
benefit, so this guide works with the existing, verified-working structure.

---

## 0. What's already deployment-safe (verified, no action needed)

- **One database connection point.** Only `app/config/config.php` opens a
  DB connection (`mysqli`) anywhere in the codebase — confirmed via a
  project-wide search. Nothing else needs editing when DB credentials change.
- **`BASE_URL`/`SITE_URL` are already computed dynamically** from the
  incoming request (`config.php`) — they are NOT hardcoded to
  `localhost/loyalty_managements`, so moving the app to a real domain does
  not require touching them.
- **Composer libraries (`firebase/php-jwt`, `endroid/qr-code`) already load
  via `vendor/autoload.php`** — standard Composer usage.
- **`uploads/.htaccess` already disables PHP execution inside `uploads/`**
  and denies web access to script-like extensions — already correct,
  Hostinger-compatible, no change made.
- **`api/admin_app_api.php` already returns clean JSON** (verified live:
  `{"success":true,"data":{...}}`) — Phase 15's requirement is already met
  for the Admin API; no response-shape changes were made.
- **No PHP file anywhere calls `mysqli_connect`/`new PDO` independently** —
  Phase 4's "de-duplicate DB connections" concern does not apply; there was
  never a duplicate to begin with.

## 1. What was changed for deployment (all additive, nothing removed)

| File | Change | Why |
|---|---|---|
| `app/config/config.php` | DB host/name/user/pass/port now read from `config.local.php` → env var → the same hardcoded local default, in that order (same pattern already used for `jwt_secret`) | Lets Hostinger use different DB credentials with **zero code edits** |
| `app/config/config.php` | Added `APP_ENV` (default `local`); when `production`, disables `display_errors` and enables `log_errors` | Phase 16 — stop leaking SQL/paths/stack traces to visitors |
| `app/config/config.php` | `WS_HOST`/`WS_PORT` now also overridable the same way | Consistency; see §4 for why this alone doesn't make chat "real-time" on shared hosting |
| `modules/admin/messages.php`, `modules/staff/messages.php`, `customer/messages.php`, `customer/rider_chat.php` | The in-page chat JS connected to a hardcoded `ws://localhost:8080`. Changed to `ws://${window.location.hostname}:<?php echo WS_PORT; ?>` | A hardcoded `localhost` in browser JS always means "the visitor's own machine" — it can **never** reach your server once deployed. Now it at least targets the right host (see §4 for the remaining hosting-tier limitation) |
| `.htaccess` (new, project root) | HTTPS redirect (skipped on `localhost`/`127.0.0.1`), blocks direct HTTP access to `config.local.php`, `composer.lock`, and any dotfile, disables directory listing | Hostinger-safe (no VPS-only directives), verified it does not break local XAMPP requests |
| `.env.example` (new) | Documents the same variable names `config.php` now reads, plus a copy-paste `config.local.php` template | Requested format; see the file's own header for why this project uses `config.local.php` rather than a `.env` parser (no `phpdotenv` dependency exists in `composer.json`, and adding one wasn't necessary) |

Everything above was tested live against the running XAMPP instance after
the change (see "Local regression test results" in the chat report). No
other file was moved, renamed, or deleted.

## 2. Step-by-step deployment

### STEP 1 — Export the local MySQL database
Do **not** reuse the stale `capstone_db.sql` sitting at the project root
(it predates this session's schema/table changes). Generate a fresh export:

```
"C:\xampp\mysql\bin\mysqldump.exe" -u root capstone_db > capstone_db_export.sql
```

**Before importing it on Hostinger**, open the file and remove (or replace
`` `root`@`localhost` `` with your real Hostinger DB username in) these two
lines — they belong to the `tbl_customer_records` and `tbl_user_accounts`
views, both of which the app actually queries:

```
/*!50013 DEFINER=`root`@`localhost` SQL SECURITY DEFINER */
```

Shared-hosting MySQL users are not granted `SUPER`/`SET_USER_ID` privilege,
so `CREATE VIEW ... DEFINER=other_user` fails to import as-is. Deleting the
`DEFINER=...` clause entirely is the simplest fix — MySQL then defaults the
view's security context to whichever account performs the import.
The rest of the export is already Hostinger-safe: no `CREATE DATABASE`/
`DROP DATABASE` statements were found.

### STEP 2 — Create the MySQL database/user in Hostinger
In hPanel → Databases → MySQL Databases: create a database and a user, and
note the exact **database host** (Hostinger sometimes uses a host name
that is *not* `localhost` — check the panel), database name, username, and
password it gives you.

### STEP 3 — Import the SQL via Hostinger's phpMyAdmin
hPanel → Databases → phpMyAdmin → select the new database → Import → choose
your edited `capstone_db_export.sql`.

### STEP 4 — Upload the PHP project files to `public_html`
Upload the entire project (excluding what's in `.gitignore`: `vendor/`,
`uploads/*` runtime contents, `database_backups/`, local scratch files) to
`public_html/` (or a subfolder, if you want the app at a sub-path — `BASE_URL`
auto-detects either way since it's derived from `SCRIPT_NAME`).

### STEP 5 — Restore Composer dependencies
`vendor/` is gitignored and was **not** uploaded in Step 4. Either:
- **Simplest:** zip your local `vendor/` folder and upload/extract it via
  Hostinger's File Manager, or
- If your Hostinger plan includes SSH access: `composer install --no-dev`
  from the project root on the server.

Without `vendor/autoload.php` present, login/JWT verification and QR-code
generation will fatal-error immediately — this is not optional.

### STEP 6 — Configure production environment
Create `app/config/config.local.php` **directly on the server** (never
commit it) using the template at the bottom of `.env.example`:

```php
<?php
return [
    'APP_ENV'        => 'production',
    'DB_HOST'        => 'YOUR_HOSTINGER_DB_HOST',
    'DB_NAME'        => 'YOUR_HOSTINGER_DB_NAME',
    'DB_USER'        => 'YOUR_HOSTINGER_DB_USER',
    'DB_PASS'        => 'YOUR_HOSTINGER_DB_PASSWORD',
    'jwt_secret'     => 'GENERATE_A_NEW_RANDOM_64_CHAR_STRING',
    'GEMINI_API_KEY' => 'your_key_or_leave_blank',
    'SMTP_PASSWORD'  => 'your_gmail_app_password',
];
```

**Important — rotate these secrets, don't reuse the local ones.** The
current local `jwt_secret`, `GEMINI_API_KEY`, and `SMTP_PASSWORD` were
already flagged (in this project's own `config.local.php` header comment)
as compromised via prior git history and must be regenerated, not copied.

### STEP 7 — Set upload directory permissions
`uploads/` (and its subfolders: `proofs/`, product image folders, etc.) must
be writable by the web server (typically `755` on shared hosting; Hostinger
usually leaves new folders writable by default — verify by testing an actual
upload, e.g. a GCash receipt upload, and check File Manager permissions if it
fails).

### STEP 8 — Enable HTTPS
Hostinger auto-issues a free SSL certificate for hosted domains — activate
it in hPanel if it isn't already. The new root `.htaccess` will then start
redirecting `http://` → `https://` automatically (it does nothing on
`localhost`, so this never affects local development).

### STEP 9 — Set the production API URL
No action needed — `BASE_URL`/`API_BASE_URL` are derived automatically from
the live domain. Riders and admins use the web portals (`/rider/`,
`/modules/admin/`); the old Flutter apps were removed.

### STEP 10 — Test the production system
Use `DEPLOYMENT_CHECKLIST.md` end to end.

---

## 4. WebSocket real-time chat — the one item that needs a decision

`ws/run_ws_server.php` is a **long-running Ratchet process**
(`php ws/run_ws_server.php`, started manually today per `ws/INSTALL.md`).
Ordinary Hostinger **shared hosting cannot run a persistent background
process or hold an open listening socket** — this is a hosting-tier
limitation, not something any code change here can fix.

The good news: `app/helpers/ws_push_helper.php` already fails **silently
and non-blockingly** if the WS server is unreachable — verified in its own
docblock ("If the WS server is offline, this helper silently fails"). That
means:
- Nothing crashes or errors out if you deploy to Hostinger shared hosting
  without a WS server running.
- Notifications still persist to the database and still appear via normal
  page loads/polling — you only lose the instant "live push" behavior.

**`WS_ENABLED` switch (app/config/config.php):** on by default locally, off by
default when `APP_ENV` is `production`. When off, `ws_push_helper.php` skips the
push attempt and no page opens a socket (an https page opening `ws://` throws,
and the chat pages would otherwise retry every 2s forever). Set
`'WS_ENABLED' => true` in `config.local.php` only once a WS server is reachable
(over `wss://` when the site is on https).

**If instant push matters for launch, your options (pick one, outside the
scope of this audit):**
1. Keep it out of scope for v1 — ship without live push, rely on the
   existing DB-backed notifications (already working).
2. Upgrade to a Hostinger VPS plan (supports persistent processes).
3. Run the WS server on a separate small always-on host (e.g. a cheap VPS
   or a process host) and point `WS_HOST`/`WS_PORT` at it via
   `config.local.php`.

This is flagged rather than silently "fixed" because guessing wrong here
(e.g. quietly disabling the feature, or claiming it'll work on shared
hosting) would misrepresent what was actually verified.

## 5. API architecture

The Flutter apps were removed; riders and admins use the web portals. The
web pages still call these endpoints with their login cookie:
- `api/admin_app_api.php` — single action router (Order Details > Assign
  rider / Email e-receipt use it).
- `modules/rider/*_api.php` — the Rider Portal (`/rider/`) endpoints.

## 6. Troubleshooting

| Symptom | Likely cause | Fix |
|---|---|---|
| "Service Unavailable" / DB error page | Wrong `DB_HOST`/`DB_USER`/`DB_PASS`/`DB_NAME` in `config.local.php`, or Hostinger DB host isn't `localhost` | Re-check hPanel's exact DB host string |
| "Access denied for user" in MySQL | Wrong password, or the DB user isn't granted to that specific database | Re-check the user↔database privilege grant in hPanel |
| 404 on an API endpoint | File wasn't uploaded, or was uploaded to the wrong relative path | Confirm the file exists at the exact same relative path as in this repo — nothing was relocated |
| 500 PHP error | Missing PHP extension, or `vendor/` missing | Check Hostinger's PHP error log (hPanel); confirm `vendor/autoload.php` exists |
| CORS error in a browser console | Only relevant if you serve a separate JS frontend origin calling the API; the existing web pages are same-origin PHP, so this shouldn't occur for them | If you later add a separate JS/mobile-web client on another origin, add explicit `Access-Control-Allow-Origin` headers to that specific API file — do not blanket-enable CORS project-wide |
| "Invalid or expired token" right after deploying | `jwt_secret` differs between where a token was issued and where it's verified (e.g. you copied the local secret instead of generating a new production one, then later regenerated it again) | Pick one production `jwt_secret` in `config.local.php` and leave it stable — regenerating it invalidates every existing session/JWT cookie, so only do it deliberately |
| "Class not found" / "Failed opening required vendor/autoload.php" | `vendor/` wasn't uploaded (it's gitignored, so a plain git-based deploy won't include it) | See Step 5 |
| Uploaded images/receipts 404 after deploy | `uploads/` runtime contents are gitignored (`/uploads/*`) — only the folder structure and `.htaccess` are tracked | Existing uploads need to be copied separately (FTP/File Manager) if you want historical files to carry over; new uploads will work once the folder is writable |
| Relative paths break on a sub-folder install | `BASE_URL` is auto-derived from `SCRIPT_NAME`, already tested working at `/loyalty_managements/` locally | If installing at Hostinger's domain root instead, this auto-detection already accounts for that too (verified logic strips only `/modules|/app|/customer` suffixes) — no manual path edits expected |
| HTTP/HTTPS mixed-content warnings | Some absolute URL was hardcoded as `http://` | `SITE_URL` already detects HTTPS dynamically (`config.php`); if a specific page still shows mixed content, it's a page-local hardcoded `http://` worth reporting rather than blanket-replacing |
