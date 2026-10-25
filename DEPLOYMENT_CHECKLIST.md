# Deployment Checklist — Loyalty Management System

See `README_DEPLOYMENT.md` for the detailed explanation behind each step,
including the item that needs a manual decision (WebSocket real-time chat
on shared hosting).

## Database
- [ ] Fresh database exported (NOT the stale root `capstone_db.sql` — see README §Step 1)
- [ ] The two `DEFINER=`root`@`localhost`` lines removed/edited in the export
- [ ] Hostinger MySQL database + user created
- [ ] Database imported via Hostinger phpMyAdmin without errors
- [ ] Exact Hostinger DB host/name/user/password recorded

## Files & configuration
- [ ] PHP project files uploaded to `public_html` (excluding gitignored paths)
- [ ] `vendor/` restored on the server (uploaded directly, or `composer install --no-dev` via SSH)
- [ ] `app/config/config.local.php` created directly on the server with production values
- [ ] Production `jwt_secret` is a NEW random value (not the local one — that one is already flagged compromised)
- [ ] `GEMINI_API_KEY` / `SMTP_PASSWORD` rotated to fresh values, not the local ones
- [ ] `APP_ENV` set to `production` in `config.local.php`
- [ ] `uploads/` and subfolders confirmed writable
- [ ] HTTPS/SSL active in hPanel
- [ ] Root `.htaccess` present (blocks `config.local.php`, dotfiles, `composer.lock`; redirects to HTTPS)

## Web portals
- [ ] Admin Web: login works
- [ ] Admin Web: dashboard loads
- [ ] Admin Web: customers, products, delivery, remittance, loyalty points, rewards, reward redemption, staff management, activity logs, messages, reviews, analytics, settings all load without PHP errors
- [ ] Staff Web: login works
- [ ] Staff Web: dashboard, customers, products, orders, order details, loyalty points, rewards, reward redemption, messages, reviews all load
- [ ] Customer Web: registration works
- [ ] Customer Web: login works
- [ ] Customer Web: products, cart, checkout, payment (GCash/Bank/COD), delivery/pickup, orders, order details, cancellation, profile, addresses, loyalty points, rewards, messages, rider chat, notifications, reviews all work

## APIs / Rider Portal
- [ ] `api/admin_app_api.php` reachable over HTTPS and still returns JSON (`app_health` action)
- [ ] Order Details > Assign rider works (uses `api/admin_app_api.php`)
- [ ] Rider Portal (`/rider/`) login works against production
- [ ] Store scan (To Ship -> Out for Delivery) and customer scan (-> Completed) work
- [ ] Remittance submission works from the Rider Portal
- [ ] Notifications appear (via DB/polling at minimum — see WebSocket note below)
- [ ] Logout works on both portals

## Known limitations to confirm a decision on
- [ ] WebSocket real-time chat: confirmed OFF (DB-backed notifications only) for launch, OR a WS-capable host arranged (README §4)

## Final checks
- [ ] Dark mode still works (Admin + Customer, both independently — unaffected by this audit)
- [ ] No `localhost` / `127.0.0.1` / `10.0.2.2` / LAN-IP references remain in anything actually read at runtime in production (see README's localhost-reference table in the chat report — the remaining hits are either doc comments, PHPMailer's own internal fallback default, or the now-dynamic WS host)
- [ ] No PHP fatal errors in Hostinger's error log after a full click-through
- [ ] No browser console errors on each portal's main pages
