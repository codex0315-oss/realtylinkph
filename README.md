# RealtyLink PH

A real-estate marketplace for the Philippines — PRC-verified agents, reviewed
listings, 360° virtual tours, real-time buyer↔agent chat, and an AI assistant
that answers buyers while an agent is offline.

- **Backend** — Laravel 12 REST API + Reverb websockets (`realtylinkph-backend`)
- **Frontend** — Nuxt 4 / Vue 3 SPA (`realtylinkph-frontend`)
- **Database** — PostgreSQL

---

## Running it on another machine

### 1. Prerequisites

| Tool | Version | Notes |
|---|---|---|
| PHP | 8.2+ | with `openssl pdo_pgsql pgsql mbstring fileinfo curl zip sodium` |
| Composer | 2.x | |
| Node.js | 20+ | |
| PostgreSQL | 14+ | |
| Git | any | |

**PHP extensions matter.** Check with `php -m`. If `pdo_pgsql` is missing the
app cannot reach the database at all. On Windows, uncomment the matching
`extension=` lines in `php.ini` — the standalone Windows build of PHP ships
**no `php.ini` at all**, so you may need to copy `php.ini-development` to
`php.ini` first.

**PHP also needs a CA bundle on Windows**, or every outbound HTTPS call fails
with `cURL error 60` — that silently breaks Google sign-in, Gemini, Groq,
Geoapify and email all at once. Download <https://curl.se/ca/cacert.pem> and
point `php.ini` at it:

```ini
curl.cainfo    = "C:\php\extras\ssl\cacert.pem"
openssl.cafile = "C:\php\extras\ssl\cacert.pem"
```

Two more `php.ini` values, or listing-photo uploads fail confusingly:

```ini
upload_max_filesize = 12M
post_max_size       = 20M
```

### 2. Clone

```bash
git clone https://github.com/codex0315-oss/realtylinkph.git
cd realtylinkph
```

### 3. Database

```bash
createdb realtylinkph          # or: CREATE DATABASE realtylinkph; in psql
```

### 4. Backend

```bash
cd realtylinkph-backend
composer install
cp .env.example .env           # Windows: copy .env.example .env
php artisan key:generate
```

Open `.env` and fill in at minimum:

- `DB_PASSWORD` — your local Postgres password
- `REVERB_APP_ID`, `REVERB_APP_KEY`, `REVERB_APP_SECRET` — any random strings
- `GROQ_API_KEY` / `GEMINI_API_KEY` — for the AI features
- `GEOAPIFY_KEY` — for maps
- `GOOGLE_CLIENT_ID` / `GOOGLE_CLIENT_SECRET` — for "Continue with Google"

Everything else has a working default. For email, `MAIL_MAILER=log` writes
messages to `storage/logs/laravel.log` instead of sending them — fine for dev.

Then:

```bash
php artisan migrate --seed
php artisan storage:link       # makes uploaded photos reachable at /storage/...
```

### 5. Frontend

```bash
cd ../realtylinkph-frontend
npm install
cp .env.example .env           # Windows: copy .env.example .env
```

⚠️ **`NUXT_PUBLIC_REVERB_KEY` must equal the backend's `REVERB_APP_KEY`**, and
the host/port must match too. If they differ, everything still loads but
real-time chat, typing indicators and notifications quietly stop working.

---

## Starting the app

Four processes. Use four terminals (or a tool like `concurrently`):

```bash
# 1 — API
cd realtylinkph-backend && php artisan serve

# 2 — websockets (real-time chat + notifications)
cd realtylinkph-backend && php artisan reverb:start

# 3 — queue worker (emails, AI auto-replies)
cd realtylinkph-backend && php artisan queue:listen

# 4 — frontend
cd realtylinkph-frontend && npm run dev
```

Open <http://localhost:3000>.

### Why `queue:listen` and not `queue:work`

`queue:work` loads your PHP classes **once at startup** and never re-reads them,
so after any backend edit it keeps running the old code — jobs then fail with
confusing "undefined method" errors. `queue:listen` boots a fresh process per
job, so it always runs current code. Slightly slower per job; irrelevant locally.

### Optional: the scheduler

```bash
cd realtylinkph-backend && php artisan schedule:work
```

Runs two hourly jobs: the homepage ranking refresh, and deleting the documents
of rejected agent applications once their re-apply cooldown lapses. The app
works without it; those two just don't happen on their own.

---

## Test accounts

`php artisan migrate --seed` creates accounts that all share the password
**`password123`**:

| Role | Email |
|---|---|
| Admin | `admin@realtylinkph.test` |

The seeder prints the rest when it runs.

---

## Troubleshooting

| Symptom | Cause |
|---|---|
| `could not find driver` | `pdo_pgsql` not enabled in `php.ini` |
| `cURL error 60` on Google / AI / email | No CA bundle — see Prerequisites |
| Messages only appear after a refresh | Reverb not running, or the Reverb key/host differ between the two `.env` files |
| Emails and AI replies never happen | No queue worker running |
| Photo upload fails with no clear error | `upload_max_filesize` / `post_max_size` too low in `php.ini` |
| Blank page after `npm run dev` | Frontend `.env` missing — copy it from `.env.example` |

---

## A note on what is *not* in this repo

By design, none of these are committed:

- **`.env` files** — they hold the DB password, Google OAuth secret, SMTP
  password and the AI/map API keys. Use `.env.example` as your template.
- **Database dumps** (`*.sql`) — they contain real user emails and password hashes.
- **`storage/app/public/`** — uploaded listing photos and, more importantly,
  agents' government IDs, licence cards and live face scans. A fresh clone
  starts with empty upload folders, which is correct.
