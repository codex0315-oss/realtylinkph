# Deploying RealtyLink PH

Frontend → **Vercel**. Backend → **Render** (Docker). Database → **Neon** Postgres.
Real-time → **Pusher**. Uploads → **Cloudflare R2 or AWS S3**.

> Work top to bottom. Steps 1–3 are the ones that break a demo if skipped.

---

## 0. Two things about Render that shape everything below

**Render has no native PHP runtime.** It builds Node, Python, Ruby, Go, Rust
and Elixir. PHP has to go through Docker — hence `realtylinkph-backend/Dockerfile`.
Choose **Docker** as the language when creating the service; there is no
build/start command to type.

**The free tier has no Background Workers and no Cron Jobs.** They are paid-only.
The queue (emails, the AI auto-reply, geocoding) and the hourly scheduled
commands therefore run *inside the web service container*, started by
`docker-entrypoint.sh` when `RUN_WORKER` / `RUN_SCHEDULER` are set.

**The free tier sleeps after 15 minutes without inbound traffic** and takes
30–60 s to wake — long enough that the server-rendered home page on Vercel
gave up and showed a 504. The entrypoint therefore requests its own public
URL (`https://$RENDER_EXTERNAL_HOSTNAME/up`) every 10 minutes, which counts
as traffic. One always-on web service fits in the 750 free instance-hours a
month. Set `KEEP_ALIVE=false` to let it sleep again (e.g. after the defense).

That is not how you would scale this, and it is worth saying out loud in a
demo. The alternative — `QUEUE_CONNECTION=sync` — is worse: on the sync driver
a failing job throws into the HTTP request, so one rejected email turns a
successful registration into a 500.

---

## 1. File storage — do this first

**Render's filesystem is ephemeral.** It is wiped on every deploy, restart and
sleep/wake. Anything written to the app's own disk is gone by the next deploy,
so listing photos and agent documents would vanish mid-demo.

Any S3-compatible bucket works. The live deployment uses **Supabase Storage**
(free, no card required). Cloudflare R2 and AWS S3 work identically — only
the env values differ.

### Supabase (what production uses)

1. supabase.com → New project → region Asia-Pacific.
2. **Storage → New bucket** → name `realtylinkph` → **Public bucket ON**.
3. **Storage → S3** (left sidebar): confirm "Enable connection via S3
   protocol" is on; copy the Endpoint and Region; **New access key**.

```
UPLOAD_DISK=s3
FILESYSTEM_DISK=s3
AWS_ACCESS_KEY_ID=<S3 access key>
AWS_SECRET_ACCESS_KEY=<S3 secret — shown once>
AWS_DEFAULT_REGION=<Region from the S3 page, e.g. ap-south-1>
AWS_BUCKET=realtylinkph
AWS_ENDPOINT=https://<project-ref>.storage.supabase.co/storage/v1/s3
AWS_URL=https://<project-ref>.storage.supabase.co/storage/v1/object/public/realtylinkph
AWS_USE_PATH_STYLE_ENDPOINT=true
```

Note the `.storage.` in the hostname and the bucket name at the end of
`AWS_URL` — both are Supabase's format. Public objects are served with
`Access-Control-Allow-Origin: *`, so the 360° viewer needs no CORS policy.

⚠️ **Free Supabase projects pause after 7 days of inactivity.** A paused
project 404s every photo. Before a demo, check the dashboard says *Active*;
if not, *Restore* takes about a minute.

### Cloudflare R2 / AWS S3

```
AWS_DEFAULT_REGION=auto            # 'auto' for R2; a real region for S3
AWS_ENDPOINT=https://<account-id>.r2.cloudflarestorage.com   # blank for S3
AWS_URL=https://pub-xxxxxxxx.r2.dev                          # public bucket URL
```

R2 needs a CORS policy on the bucket allowing `GET` from the Vercel origin,
or the 360° viewer's WebGL texture load is refused.

Make the bucket's objects publicly readable (listing photos are shown to
anonymous visitors). Agent documents are only ever linked from admin screens,
but note they live in the same bucket — if that matters to you, use a second
private bucket and signed URLs.

Nothing in the code needs changing: every upload goes through
`App\Support\Uploads`, which reads `config('filesystems.uploads')`.

If you skip this step the app still runs — uploads just don't survive a
redeploy. Everything else works.

---

## 2. Pusher — real-time without a second service

Reverb speaks the Pusher protocol, so hosted Pusher is a drop-in replacement.
Use it in production: a self-hosted Reverb on a free instance sleeps when idle,
and a socket that takes ~50s to wake reads as "chat is broken".

1. Create a free app at **pusher.com** → Channels. Pick a cluster near you
   (`ap1` is Singapore).
2. From the app's **App Keys** tab, set on Render:

```
BROADCAST_CONNECTION=pusher
PUSHER_APP_ID=...
PUSHER_APP_KEY=...
PUSHER_APP_SECRET=...
PUSHER_APP_CLUSTER=ap1
PUSHER_SCHEME=https
PUSHER_PORT=443
```

3. And on Vercel:

```
NUXT_PUBLIC_PUSHER_KEY=<same as PUSHER_APP_KEY>
NUXT_PUBLIC_PUSHER_CLUSTER=<same as PUSHER_APP_CLUSTER>
```

Setting **both** Vercel variables is what switches the frontend's transport
(see `app/plugins/echo.client.ts`). Leave them unset locally and development
keeps using Reverb, unchanged.

Skipping Pusher is survivable — messages then need a refresh to appear.

---

## 3. Render — create the services in this order

### 3a. Database first — Neon, not Render Postgres

**Render's free Postgres is deleted 30 days after creation.** Ours would have
expired on the day of the defense. Neon's free tier has no expiry.

1. **neon.tech** → New project → region **AWS US West 2 (Oregon)** — the same
   region as the Render web service, so queries don't cross the Pacific.
2. **Connect** → turn **Connection pooling OFF** (Laravel's prepared statements
   and the pooler don't always agree) → Show password → copy the values.
3. Set on Render: `DB_HOST`, `DB_PORT=5432`, `DB_DATABASE=neondb`,
   `DB_USERNAME`, `DB_PASSWORD`, and **`DB_SSLMODE=require`** — Neon refuses
   plaintext connections.

The compute suspends after 5 idle minutes and wakes in ~1–2 s on the next
query; behind Render's own cold start it isn't noticeable.

### 3b. Web Service

**New → Web Service** → connect the `realtylinkph` repo.

| Setting | Value |
|---|---|
| **Language** | `Docker` |
| **Root Directory** | `realtylinkph-backend` |
| **Dockerfile Path** | `./Dockerfile` (relative to root directory) |
| **Health Check Path** | `/up` |

There is no build or start command — the Dockerfile and its entrypoint define
both.

---

## 4. Environment — production values

On the Render **web service**:

```
APP_NAME=RealtyLinkPH
APP_ENV=production
APP_DEBUG=false                 # ← critical: true leaks stack traces publicly
APP_KEY=<php artisan key:generate --show>
APP_URL=https://realtylinkph-api.onrender.com
APP_TIMEZONE=Asia/Manila
FRONTEND_URL=https://realtylinkph.vercel.app
LOG_CHANNEL=stderr              # ← not `stack`: that writes to a file inside the
LOG_LEVEL=warning               #   container, invisible in Render's log viewer

MAIL_FROM_ADDRESS=you@example.com
MAIL_FROM_NAME=RealtyLinkPH

DB_CONNECTION=pgsql             # from the Neon Connect panel (pooling OFF)
DB_HOST=ep-....us-west-2.aws.neon.tech
DB_PORT=5432 DB_DATABASE=neondb DB_USERNAME=neondb_owner DB_PASSWORD=...
DB_SSLMODE=require

QUEUE_CONNECTION=database
SESSION_DRIVER=database
CACHE_STORE=database

# Free-tier substitutes for a Background Worker and a Cron Job (see §0).
RUN_WORKER=true
RUN_SCHEDULER=true

CORS_ALLOWED_ORIGINS=https://realtylinkph.vercel.app
# Vercel preview deploys get generated subdomains — allow them if you use them:
# CORS_ALLOWED_ORIGIN_PATTERNS=#^https://realtylinkph-.*\.vercel\.app$#

GOOGLE_REDIRECT_URI=https://realtylinkph.vercel.app/google/callback
GOOGLE_LOGIN_REDIRECT_URI=https://realtylinkph.vercel.app/auth/google/callback
```

Generate a **fresh** `APP_KEY` for production rather than reusing the local one:

```bash
cd realtylinkph-backend && php artisan key:generate --show
```

Plus the storage block from §1, the Pusher block from §2, and your
`GROQ_API_KEY`, `GEMINI_API_KEY`, `GEOAPIFY_KEY`, `GOOGLE_CLIENT_ID`,
`GOOGLE_CLIENT_SECRET` and `MAIL_*` values.

⚠️ **Both Google redirect URIs must also be added to the Google Cloud console**
(APIs & Services → Credentials → your OAuth client), or sign-in fails with
`redirect_uri_mismatch`.

The OAuth client lives in Google Cloud project **`realtylinkph`** (ID
`realtylinkph-508915`) under the `ericsonbareno028@gmail.com` account — not
the `codex0315` account. The client ID's leading number is the *project
number*, which is how to find the right project if that ever changes again.

To let **any** Google account sign in (not just listed test users), the
consent screen must be *Published*. Publishing is blocked until Branding has
an **Authorized domain** (`realtylinkph.vercel.app`); the error message only
says "complete your Branding configuration" without naming the field. Don't
upload an app logo — that forces Google's verification review.

⚠️ **Email must use `MAIL_MAILER=brevo`, not `smtp`.** Render's free tier
blocks outbound SMTP ports (25, 465, 587): the `smtp` mailer connects fine
from a laptop and *times out* from Render, and the failure only shows up in
`failed_jobs` because the send is queued. The `brevo` mailer sends the same
mail through Brevo's HTTPS API on port 443 instead. It needs the **v3 API key**
(`xkeysib-…`, from Brevo → SMTP & API → *API Keys* tab), which is a different
key from the SMTP one:

```
MAIL_MAILER=brevo
BREVO_API_KEY=xkeysib-...
```

`MAIL_MAILER=log` is the fallback if mail needs to be switched off — emails
then go to the log instead of failing.

On **Vercel**:

```
NUXT_PUBLIC_API_BASE=https://realtylinkph-api.onrender.com/api
NUXT_PUBLIC_APP_URL=https://realtylinkph.vercel.app
NUXT_PUBLIC_GEOAPIFY_KEY=...
NUXT_PUBLIC_PUSHER_KEY=...
NUXT_PUBLIC_PUSHER_CLUSTER=ap1
```

Vercel settings: **Root Directory** `realtylinkph-frontend`, framework Nuxt
(auto-detected).

---

## 5. Migrations and seeding

**Migrations run on every boot.** The entrypoint calls
`php artisan migrate --force`, which only applies migrations that haven't run
yet — a no-op on a routine deploy, and exactly right after a schema change.
Set `SKIP_MIGRATIONS=true` to opt out of a specific deploy.

**Seeding is opt-in.** Set `RUN_SEEDERS=true` for one deploy to create the
demo admin (`admin@realtylinkph.test` / `password123`), demo agents and
listings, then remove it. The seeder is idempotent — it checks for the demo
admin and does nothing if present — so leaving it on is wasteful, not
dangerous.

**Photo backfill is opt-in.** Listing photos are resized on upload (1600 px
main + 640 px card thumbnail, `PropertyPhotoService`). Photos uploaded before
that existed have no thumbnail — the API falls back to the main image for
them — and may be full-size phone originals. Set `RUN_PHOTO_OPTIMIZE=true`
for one deploy to run `php artisan photos:optimize` at boot, then remove it.

**Applicant documents live in a private bucket.** Government IDs, licence
cards and selfies are written to the `documents` disk (`DOCUMENT_DISK`), never
the public one, and admins only ever receive 15-minute signed URLs. Setup:

1. Supabase → Storage → New bucket → name `realtylinkph-private`, **Public
   bucket OFF**. Same S3 keys work for both buckets.
2. Render env: `DOCUMENT_DISK=s3-private` (and `AWS_PRIVATE_BUCKET` only if you
   used a different name).
3. One deploy with `RUN_DOCS_MOVE=true` moves the files already uploaded to
   the public bucket across, then remove the flag.

Until step 2 is done the app falls back to `DOCUMENT_DISK=local`, which on
Render is wiped on every deploy — so do it before anyone applies.

There is no Shell on Render's free tier, which is why these are boot flags.

`php artisan storage:link` is handled by the entrypoint and is a no-op when
`UPLOAD_DISK=s3`.

---

## 6. Point the two halves at each other

After Render gives you the API URL:

1. Update `NUXT_PUBLIC_API_BASE` on Vercel → redeploy.
2. Confirm `CORS_ALLOWED_ORIGINS` on Render exactly matches the Vercel domain,
   scheme included and **no trailing slash**.

A mismatch here is the single most confusing failure in this stack: the API
answers fine in Postman and every browser request fails. Check the browser
console for a CORS message before suspecting anything else.

---

## 7. Rotate your keys

Every credential that has lived in a local `.env` — or been pasted into a chat
or screenshot — should be regenerated before it protects a public service:
Groq, Gemini, Geoapify, Google OAuth client secret, Brevo SMTP, and the
database password. Restrict the Geoapify key to your Vercel domain; it ships to
the browser and is readable by anyone who opens devtools.

---

## Before you demo

- [ ] Open the site ~2 minutes early anyway. The container pings its own
      `/up` every 10 minutes (see §0) so Render should never put it to sleep,
      but a redeploy or a Render incident still means a ~50s cold start.
      Check the logs for `==> Keep-alive pinging`.
- [ ] Upload one listing photo and confirm it still loads after a redeploy —
      that proves S3/R2 is actually wired up.
- [ ] Send a message between two accounts to confirm Pusher is connected.
- [ ] Check the logs say `==> Starting queue worker` (queue jobs = emails +
      AI replies).
- [ ] Confirm `APP_DEBUG=false`: visit a bad URL and check you get a plain
      error page, not a stack trace.

---

## Where the time goes (performance notes)

Measured from Manila on 2026-09-19, before the fixes in this section:

| | Before | Why |
|---|---|---|
| Warm API call | ~300 ms | PHP recompiled all of Laravel on every request — the official `php:8.4-apache` image ships with OPcache **off** |
| Home page TTFB | 2.3–2.9 s | Vercel function (US East) waited on the API (Oregon) before sending any HTML |
| First visit after 15 min idle | 30–60 s | Render free tier sleep |
| Browse page images | up to 10 MB *per card* | photos stored as uploaded, `cache-control: no-cache` |

What now keeps it fast, and where to look if it regresses:

- **OPcache** is enabled in the Dockerfile (`opcache.ini`, timestamps off —
  code never changes inside a container).
- **Keep-alive** in `docker-entrypoint.sh` (§0).
- **Edge caching** — `routeRules` in `nuxt.config.ts` mark `/`, `/properties`
  and `/agents*` as `isr` (stale-while-revalidate, 60–300 s). Vercel serves
  the cached HTML instantly and refreshes it in the background. Listing
  detail pages are *not* cached because the API counts a view per render.
- **Photos** are resized on upload and served with a one-year
  `Cache-Control` (set on the `s3` disk in `config/filesystems.php`, so
  avatars and documents get it too). Cards use `thumb_url`.
- **Three.js** (the 360° viewer, 170 KB gzipped) only downloads when a
  listing actually has panoramas (`LazyProperty360Viewer`).

---

## Moving the backend to Singapore (optional, ~1–2 h)

Measured from Cebu on 2026-09-20: a TCP round trip to AWS Singapore is
~110 ms, to Oregon ~280 ms. Every API call pays that at least once, so
moving Render + Neon to Singapore takes the per-call floor from ~250 ms to
~80 ms. Nothing in the code changes; it is all account work. Keep the old
service running until the new one is verified — there is no downtime and
the old one is the rollback.

**1. New Neon project in Singapore (5 min)**
- Neon → New project → region **AWS Asia Pacific (Singapore)** `ap-southeast-1`,
  Postgres 17. Copy the *direct* (non-pooler) connection string.

**2. Copy the data (10 min)** — from this PC, PostgreSQL 18 client is installed:
```
set PGSSLMODE=require
"C:\Program Files\PostgreSQL\18\bin\pg_dump.exe" -Fc --no-owner --no-privileges ^
  "postgresql://neondb_owner:<OLD_PASSWORD>@ep-proud-mud-arfxks88.c-4.us-west-2.aws.neon.tech/neondb" ^
  -f realtylinkph.dump
"C:\Program Files\PostgreSQL\18\bin\pg_restore.exe" --no-owner --no-privileges -d ^
  "postgresql://neondb_owner:<NEW_PASSWORD>@<NEW_HOST>.ap-southeast-1.aws.neon.tech/neondb" ^
  realtylinkph.dump
```
Do this right before switching over (step 5) so nothing written in between
is lost, or put the old service in maintenance first.

**3. New Render web service in Singapore (15 min)**
- Render → New → Web Service → same repo, branch `main`, Root Directory
  `realtylinkph-backend`, Language **Docker**, Region **Singapore**, Free.
- Environment: copy every variable from the Oregon service (Render →
  old service → Environment → the "copy" icon copies them all), then change
  `DB_HOST` / `DB_PASSWORD` to the new Neon values. Leave `SKIP_MIGRATIONS`
  unset — the boot migration is a no-op on a restored database.
- Deploy. Wait for `==> Keep-alive pinging` in the logs. Note the new URL,
  e.g. `https://realtylinkph-api-sg.onrender.com`.

**4. Point the other services at it (10 min)**
- Vercel → Environment Variables → `NUXT_PUBLIC_API_BASE` =
  `https://<new>.onrender.com/api` → Redeploy.
- Google Cloud → APIs & Services → Credentials → the OAuth client → add the
  new host to **Authorized redirect URIs** wherever the old one appears
  (sign-in callback and calendar callback). Keep the old entries until the
  old service is gone.
- Render (new service) → `APP_URL` = the new URL, `FRONTEND_URL` unchanged.
- `nuxt.config.ts` → the detour default `NUXT_API_PROXY_TARGET` fallback
  should be updated to the new host (one line), or set the env var on Vercel.

**5. Verify, then retire Oregon**
- Run through the demo checklist above against the new URL, including one
  Google sign-in, one calendar connect, one photo upload and one password
  reset email.
- Render → old service → Settings → **Suspend** (not delete, for a week).
- Neon → old project can be deleted after the same week.

---

## If the API is unreachable from the venue's network

Seen on 2026-09-19: the browser got `ERR_CONNECTION_TIMED_OUT` for every
API call while Vercel's own servers reached the API fine. Render's edge IPs
(`216.24.57.16/.18`, what `realtylinkph-api.onrender.com` resolves to) were
not accepting HTTPS from that Philippine ISP; even `dashboard.render.com`
failed from the same machine. Nothing in the app was wrong.

1. First try a different network — a phone hotspot takes a different route.
2. If that isn't possible, detour the API through Vercel: in the Vercel
   project set `NUXT_PUBLIC_API_BASE=https://realtylinkph.vercel.app/api`
   and redeploy (~2 min). `nuxt.config.ts` already proxies `/api/**` and
   `/broadcasting/**` to Render. Photo uploads over 4.5 MB will fail through
   the detour (Vercel's request-body cap); everything else works. Set it back
   to `https://realtylinkph-api.onrender.com/api` afterwards.

---

## Security posture (what to say if asked)

In place: bcrypt passwords with a length/letters/numbers policy; Sanctum
bearer tokens that expire after 30 days; rate limits on login (10/min),
registration, password reset (5/min), enquiries and AI calls; policy-based
authorization on every owned resource with tests for stranger access; no raw
SQL, no `v-html`; uploads validated by MIME and size and re-encoded through
GD; applicant IDs in a private bucket behind 15-minute signed URLs; CORS
locked to the Vercel origin; `APP_DEBUG=false`; HSTS (preload) plus a
Content-Security-Policy, `X-Frame-Options: DENY`, `nosniff`, a referrer
policy and a permissions policy on every page (`nuxt.config.ts`); an admin
audit trail. Secrets are never committed; `.env.example` is the template.

Known gaps for a production release: email verification isn't enforced
before booking/messaging; no admin 2FA; the CSP allows inline scripts and
styles (Nuxt hydrates through inline tags); free-tier hosting has no WAF or
alerting; every key pasted during setup must be rotated after the defense
and the seeded admin password changed before it.

---

## Known limitations (be ready to say these out loud)

- **Tests cover the five core workflows** (`php artisan test`, ~20 tests /
  180 assertions on a local Postgres): auth, agent onboarding + admin
  review, draft→published listing with photos, book→confirm→remind→
  auto-complete→review, and admin moderation with the audit trail. Screens
  are still verified by hand.
- **Queue and scheduler run inside the web container** because the free tier
  has no worker. The scheduler runs hourly: featured scoring, rejected-document
  purge, viewing reminders (`appointments:send-reminders`) and viewing
  auto-completion / request expiry (`appointments:complete-past`).
- **Free-tier cold starts** — mitigated by the in-container keep-alive, but
  Neon still pauses the database after 5 idle minutes (~1 s on the first
  query afterwards) and a redeploy always starts cold.
- **Agent documents** are in a private bucket behind 15-minute signed URLs
  (§5). The AI pre-screen reads them server-side, never via URL.
- **No admin UI to waive a cancellation strike** — the data model supports it,
  but it currently needs a database edit.
