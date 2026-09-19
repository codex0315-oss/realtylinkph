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

## Known limitations (be ready to say these out loud)

- **No automated tests.** Everything has been verified by hand.
- **Queue and scheduler run inside the web container** because the free tier
  has no worker. They stop while the instance is asleep.
- **Free-tier cold starts** — mitigated by the in-container keep-alive, but
  Neon still pauses the database after 5 idle minutes (~1 s on the first
  query afterwards) and a redeploy always starts cold.
- **Agent documents share the public bucket** — fine for a demo, would need a
  private bucket with signed URLs for real use.
- **No admin UI to waive a cancellation strike** — the data model supports it,
  but it currently needs a database edit.
