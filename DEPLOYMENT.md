# Deploying RealtyLink PH

Frontend → **Vercel**. Backend → **Render** (Docker). Database → Render PostgreSQL.
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

### 3a. PostgreSQL first

**New → Postgres.** Free instance. Once it is up, copy the **Internal Database
URL** — the internal one is faster and doesn't count against bandwidth.

Render gives a single URL; split it into the parts Laravel wants, or set
`DATABASE_URL` and let Laravel parse it.

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

DB_CONNECTION=pgsql             # from the Render Postgres dashboard
DB_HOST=... DB_PORT=5432 DB_DATABASE=... DB_USERNAME=... DB_PASSWORD=...

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

⚠️ **If Brevo is not activated yet, set `MAIL_MAILER=log`.** Emails then go to
the log instead of failing. With a real worker a failed send only fails that
job, but it still fills the log with noise on every registration.

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

## 5. First deploy — migrations

Set **`RUN_MIGRATIONS=true`** on the web service before the first deploy. The
entrypoint runs `php artisan migrate --force` on boot.

Optionally set `RUN_SEEDERS=true` for demo accounts and listings.

**Remove both afterwards.** They are opt-in precisely so a routine redeploy
never re-runs them — re-seeding duplicates data.

There is no Shell on Render's free tier, which is why these exist as flags.

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

- [ ] Open the site ~2 minutes early. **Render's free tier sleeps after 15 min
      idle and takes ~50s to wake.** A cold start mid-presentation looks broken.
- [ ] Upload one listing photo and confirm it still loads after a redeploy —
      that proves S3/R2 is actually wired up.
- [ ] Send a message between two accounts to confirm Pusher is connected.
- [ ] Check the logs say `==> Starting queue worker` (queue jobs = emails +
      AI replies).
- [ ] Confirm `APP_DEBUG=false`: visit a bad URL and check you get a plain
      error page, not a stack trace.

---

## Known limitations (be ready to say these out loud)

- **No automated tests.** Everything has been verified by hand.
- **Queue and scheduler run inside the web container** because the free tier
  has no worker. They stop while the instance is asleep.
- **Free-tier cold starts** make the first request slow.
- **Agent documents share the public bucket** — fine for a demo, would need a
  private bucket with signed URLs for real use.
- **No admin UI to waive a cancellation strike** — the data model supports it,
  but it currently needs a database edit.
