# Vindex — Members Platform

A secure, bilingual (English / Danish) members area and knowledge base for **Vindex**.
Built in plain **PHP + MySQL** so it runs on standard shared hosting such as **one.com** with nothing exotic to maintain.

Matches the public Vindex site: dark theme by default with a white-mode toggle, cyan accent, Sora + Manrope fonts.

## What it does

- **Secure login** — hashed passwords (bcrypt), hardened sessions, CSRF protection on every form, HTTPS-aware cookies.
- **Two member tiers**
  - **Leadership** — sees every section and block.
  - **Employee** — sees only content marked "Everyone". Leadership-only content is filtered **on the server**, so an Employee's browser never even receives it.
  - **Admin** — one (or more) management accounts that manage users and all content, and can see everything.
- **Left-hand sidebar** of sections (the menu going down the page). Click a section and it opens in the main panel with no page reload.
- **Fold-out content blocks** — each section is built from blocks. A *text block* shows text; a *fold-out* is a heading with an arrow that expands to reveal more. Fold-outs **nest to any depth**.
- **Full admin CMS (no code needed)**
  - Add / rename / reorder / delete sections, and set who can see each.
  - Inside a section, add text and fold-out blocks, nest them, edit English + Danish, set visibility, reorder, delete.
  - Add / remove users, set each as Employee / Leadership / Admin, reset passwords.
- **Bilingual everywhere** — every section and block has an English and a Danish version; the EN/DA toggle switches the whole interface instantly. Theme + language choices persist across pages.

## Requirements

- PHP 7.4+ or 8.x (tested on 8.3)
- MySQL 5.7+ / 8.x
- Any shared or VPS host — one.com's standard plan is enough.

## Install on one.com (about 5 minutes)

1. In the one.com control panel, create a **MySQL database** and note its name, username, password and host.
2. Upload the contents of this folder to your web space (e.g. into the site root or a `/members` subfolder).
3. Open **`config.php`** and fill in the four `DB_*` values from step 1.
4. Visit **`install.php`** in your browser (e.g. `https://vindex.dk/members/install.php`). Create your admin account. Optionally load the starter section list to begin with.
5. **Delete `install.php`** from the server.
6. Go to **`login.php`** and sign in. You're live.

Point the same files at both `vindex.dk` and `vindex.com` (or share one install between them) — HTTPS is handled by one.com's free certificate.

## Security notes

- Passwords are stored with `password_hash()` (bcrypt) — never in plain text.
- All database access uses prepared statements (no SQL injection).
- Every state-changing action is CSRF-protected and admin-gated on the server.
- Access control is enforced in the queries themselves — Employees cannot reach Leadership content by any URL.
- Session IDs regenerate on login (anti-fixation); cookies are HttpOnly, SameSite=Lax, and Secure over HTTPS.

## File map

```
config.php        <- your database details go here
install.php       <- one-time setup (delete after use)
login.php         <- member sign-in
logout.php
index.php         <- members area (sidebar + content + section API)
admin.php         <- admin CMS (users, sections, block tree)
schema.sql        <- database tables
seed.php          <- CLI demo seeder (local testing only)
lib/              <- db, auth, helpers, content rendering
assets/           <- app.css, admin.css, app.js
```

Built for Vindex. Everything above the database is the customer's to edit through the admin — no developer needed for day-to-day content.
